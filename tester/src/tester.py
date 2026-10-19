#!/usr/bin/env python3
"""
An integration testing script for the SOL26 interpreter.

IPP: You can implement the entire tool in this file if you wish, but it is recommended to split
     the code into multiple files and modules as you see fit.

     Below, you have some code to get you started with the CLI argument parsing and logging setup,
     but you are **free to modify it** in whatever way you like.

Author: Ondřej Ondryáš <iondryas@fit.vut.cz>
"""

import argparse
import logging
import os
import re
import shlex
import sys
from pathlib import Path

from discovery import discover_test_files
from executor import ExecutionConfig, execute_selected_tests
from filters import FilterArgs, partition_tests
from models import TestReport
from report_builder import build_final_report
from soltest_parser import LoadedTestCase, load_test_definitions

logger = logging.getLogger("main")


class CliArguments(argparse.Namespace):
    """
    Represents the parsed command-line arguments.
    """

    tests_dir: Path
    recursive: bool
    output: Path | None
    dry_run: bool
    include: list[str] | None
    include_category: list[str] | None
    include_test: list[str] | None
    exclude: list[str] | None
    exclude_category: list[str] | None
    exclude_test: list[str] | None
    verbose: int
    regex_filters: bool


def write_result(result_report: TestReport, output_file: Path | None) -> None:
    """
    Writes the final report to the specified output file or standard output if no file is provided.
    """
    result_json = result_report.model_dump_json(indent=2)
    if output_file:
        with output_file.open("w", encoding="utf-8") as file:
            file.write(result_json)
    else:
        print(result_json)


def parse_arguments() -> CliArguments:
    """
    Parses the command-line arguments and performs basic validation a sanitization.
    """

    # Define the CLI arguments
    arg_parser = argparse.ArgumentParser()
    arg_parser.add_argument(
        "tests_dir",
        type=Path,
        help="Path to a directory with the test cases in the SOLtest format.",
    )
    arg_parser.add_argument(
        "-r",
        "--recursive",
        action="store_true",
        help="Recursively search for test cases in subdirectories of the provided directory.",
    )
    arg_parser.add_argument(
        "-o",
        "--output",
        type=Path,
        help="The output file to write the test results to. "
        "If not provided, results will be printed to standard output.",
    )
    arg_parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Perform a dry run: discover the test cases but don't actually execute them.",
    )
    arg_parser.add_argument(
        "-i",
        "--include",
        action="append",
        help="Include only test cases with the specified name or category. "
        "Can be used multiple times to specify multiple criteria."
        "Can be combined with -ic and -it.",
    )
    arg_parser.add_argument(
        "-ic",
        "--include-category",
        action="append",
        help="Include only test cases with the specified category. "
        "Can be used multiple times to specify multiple accepted categories. "
        "Can be combined with -it and -i.",
    )
    arg_parser.add_argument(
        "-it",
        "--include-test",
        action="append",
        help="Include only test cases with the specified name. "
        "Can be used multiple times to specify multiple accepted names. "
        "Can be combined with -ic and -i.",
    )
    arg_parser.add_argument(
        "-e",
        "--exclude",
        action="append",
        help="Exclude test cases with the specified name or category. "
        "Can be used multiple times to specify multiple criteria."
        "Can be combined with -ic and -it.",
    )
    arg_parser.add_argument(
        "-ec",
        "--exclude-category",
        action="append",
        help="Exclude test cases with the specified category. "
        "Can be used multiple times to specify multiple accepted categories. "
        "Can be combined with -it and -i.",
    )
    arg_parser.add_argument(
        "-et",
        "--exclude-test",
        action="append",
        help="Exclude test cases with the specified name. "
        "Can be used multiple times to specify multiple accepted names. "
        "Can be combined with -ic and -i.",
    )
    arg_parser.add_argument(
        "-g",
        dest="regex_filters",
        action="store_true",
        help="When used, the filters specified with -i[ct]/-e[ct] will be interpreted as "
        "regular expressions instead of literal strings.",
    )
    arg_parser.add_argument(
        "-v",
        "--verbose",
        action="count",
        default=0,
        help="Enable verbose logging output (using once = INFO level, using twice = DEBUG level).",
    )

    # Parse the provided arguments
    # argparse will automatically print an error message and exit with the return code 2
    # in case of invalid arguments
    args = arg_parser.parse_args(namespace=CliArguments())

    # Check source directory
    if not args.tests_dir.is_dir():
        print("The provided path is not a directory.", file=sys.stderr)
        raise SystemExit(1)

    # Warn if the output file already exists
    if args.output:
        if not args.output.parent.exists():
            print("The parent directory of the output file does not exist.", file=sys.stderr)
            raise SystemExit(2)
        if args.output.exists():
            logger.warning("The output file will be overwritten: %s", args.output)

    return args


def _find_repo_root() -> Path | None:
    """Locates repository root by checking known parser/interpreter file paths."""
    current = Path(__file__).resolve()
    for parent in [current.parent, *current.parents]:
        has_parser = (parent / "tester" / "sol2xml" / "sol_to_xml.py").is_file() or (
            parent / "sol2xml" / "sol_to_xml.py"
        ).is_file()
        has_interpreter = (parent / "int" / "src" / "solint.php").is_file() or (
            parent / "python" / "int" / "src" / "solint.py"
        ).is_file()
        if has_parser and has_interpreter:
            return parent

    return None


def _read_command(env_name: str) -> list[str] | None:
    """Reads optional command override from environment variable."""
    configured = os.environ.get(env_name)
    return shlex.split(configured) if configured else None


def _resolve_local_command(
    repo_root: Path | None,
    relative_path: Path,
    runner: str,
) -> list[str] | None:
    """Builds local command when repository layout is available."""
    if repo_root is None:
        return None

    candidate = repo_root / relative_path
    if not candidate.is_file():
        return None

    if runner == "python":
        return [sys.executable, str(candidate)]

    return [runner, str(candidate)]


def _build_execution_config() -> ExecutionConfig:
    """Builds command configuration from env overrides or repository-local defaults."""
    repo_root = _find_repo_root()
    parser_command = (
        _read_command("SOL2XML_COMMAND")
        or _resolve_local_command(
            repo_root,
            Path("tester/sol2xml/sol_to_xml.py"),
            "python",
        )
        or _resolve_local_command(
            repo_root,
            Path("sol2xml/sol_to_xml.py"),
            "python",
        )
        or ["sol2xml"]
    )
    interpreter_command = (
        _read_command("SOL26_INTERPRETER_COMMAND")
        or _resolve_local_command(
            repo_root,
            Path("int/src/solint.php"),
            "php",
        )
        or _resolve_local_command(
            repo_root,
            Path("python/int/src/solint.py"),
            "python",
        )
        or ["solint"]
    )
    diff_command = _read_command("SOL26_DIFF_COMMAND") or ["diff"]

    return ExecutionConfig(
        parser_command=parser_command,
        interpreter_command=interpreter_command,
        diff_command=diff_command,
    )


def _collect_duplicate_name_errors(
    loaded_cases: list[LoadedTestCase],
) -> tuple[list[LoadedTestCase], dict[str, Exception]]:
    """Detects duplicate test names and returns filtered list plus corresponding errors."""
    counts: dict[str, int] = {}
    duplicate_errors: dict[str, Exception] = {}

    for test in loaded_cases:
        counts[test.name] = counts.get(test.name, 0) + 1

    duplicate_names = {name for name, count in counts.items() if count > 1}
    for name in duplicate_names:
        duplicate_errors[name] = ValueError(
            "Duplicate test name discovered. Test names must be unique even in recursive mode."
        )

    filtered_loaded = [test for test in loaded_cases if test.name not in duplicate_names]

    return filtered_loaded, duplicate_errors


def _merge_error_dicts(
    base: dict[str, Exception],
    extra: dict[str, Exception],
) -> dict[str, Exception]:
    """Merges error dictionaries without silently dropping colliding keys."""
    merged = dict(base)
    for name, exc in extra.items():
        existing = merged.get(name)
        if existing is None:
            merged[name] = exc
        else:
            merged[name] = ValueError(f"{existing}; {exc}")
    return merged


def main() -> None:
    """
    The main entry point for the SOL26 integration testing script.
    It parses command-line arguments and executes the testing process.
    """

    # Set up logging
    # IPP: You do not have to use logging – but it is the recommended practice.
    # See this for more information: https://docs.python.org/3/howto/logging.html
    logging.basicConfig(
        stream=sys.stderr,
        level=logging.WARNING,
        format="%(asctime)s %(levelname)s [%(name)s][%(filename)s:%(lineno)d] %(message)s",
    )

    # Parse the CLI arguments
    args = parse_arguments()

    # Enable debug or info logging if the verbose flag was set twice or once
    if args.verbose >= 2:
        logging.root.setLevel(logging.DEBUG)
    elif args.verbose == 1:
        logging.root.setLevel(logging.INFO)

    # Discover the candidate test files and parse their definitions.
    discovered_files = discover_test_files(args.tests_dir, args.recursive)
    discovered_test_cases, loaded_cases, malformed = load_test_definitions(discovered_files)
    loaded_cases, duplicate_errors = _collect_duplicate_name_errors(loaded_cases)
    malformed = _merge_error_dicts(malformed, duplicate_errors)

    # Partition parsed tests into selected and filtered-out groups.
    try:
        filter_args = FilterArgs.from_namespace(args)
        filter_decision = partition_tests(discovered_test_cases, filter_args)
    except re.error as exc:
        print(f"Invalid regular expression in filters: {exc}", file=sys.stderr)
        raise SystemExit(2) from exc

    if args.dry_run:
        # Dry run only reports discovery/filtering results without executing tests.
        # Mark all selected tests as unexecuted to keep the report semantically complete.
        dry_run_skipped: dict[str, Exception] = {
            test.name: RuntimeError("Dry-run mode: test case was not executed.")
            for test in filter_decision.selected
        }
        report = build_final_report(
            discovered_test_cases=discovered_test_cases,
            malformed=malformed,
            filtered_out=filter_decision.filtered_out,
            executed_results={},
            cannot_execute={},
            other_errors=dry_run_skipped,
        )
        write_result(report, args.output)
        return

    # Match selected public definitions back to their loaded internal representations.
    selected_names = {test.name for test in filter_decision.selected}
    selected_loaded_cases = [test for test in loaded_cases if test.name in selected_names]

    # Execute the selected tests and capture execution failures separately.
    executed_results, cannot_execute, other_errors = execute_selected_tests(
        selected_loaded_cases,
        _build_execution_config(),
    )

    # Build and serialize the final report.
    report = build_final_report(
        discovered_test_cases=discovered_test_cases,
        malformed=malformed,
        filtered_out=filter_decision.filtered_out,
        executed_results=executed_results,
        cannot_execute=cannot_execute,
        other_errors=other_errors,
    )
    write_result(report, args.output)


if __name__ == "__main__":
    main()
