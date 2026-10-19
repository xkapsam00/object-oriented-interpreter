"""Execution helpers for parser/interpreter test cases."""

import tempfile
from dataclasses import dataclass
from pathlib import Path

from models import TestCaseDefinition, TestCaseReport, TestCaseType, TestResult
from soltest_parser import LoadedTestCase
from utils import ProcessResult, run_process

RECOVERABLE_EXECUTION_ERRORS = (
    RuntimeError,
    ValueError,
    TypeError,
    LookupError,
)


@dataclass(slots=True)
class ExecutionConfig:
    """External command configuration used when executing tests."""

    parser_command: list[str]
    interpreter_command: list[str]
    diff_command: list[str]


def run_diff(expected_file: Path, actual_output: str, config: ExecutionConfig) -> ProcessResult:
    """Compares expected and actual stdout using configured diff command."""
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", delete=False) as tmp:
        tmp.write(actual_output)
        tmp_path = Path(tmp.name)

    try:
        return run_process([*config.diff_command, str(expected_file), str(tmp_path)])
    finally:
        tmp_path.unlink(missing_ok=True)


def make_pass_report(
    parser_result: ProcessResult | None = None,
    interpreter_result: ProcessResult | None = None,
) -> TestCaseReport:
    """Builds a passing test-case report payload."""
    return TestCaseReport(
        result=TestResult.PASSED,
        parser_exit_code=parser_result.returncode if parser_result else None,
        interpreter_exit_code=interpreter_result.returncode if interpreter_result else None,
        parser_stdout=parser_result.stdout if parser_result else None,
        parser_stderr=parser_result.stderr if parser_result else None,
        interpreter_stdout=interpreter_result.stdout if interpreter_result else None,
        interpreter_stderr=interpreter_result.stderr if interpreter_result else None,
    )


def make_parse_fail_report(parser_result: ProcessResult) -> TestCaseReport:
    """Builds report for unexpected parser exit code."""
    return TestCaseReport(
        result=TestResult.UNEXPECTED_PARSER_EXIT_CODE,
        parser_exit_code=parser_result.returncode,
        parser_stdout=parser_result.stdout,
        parser_stderr=parser_result.stderr,
    )


def make_interpreter_fail_report(
    interpreter_result: ProcessResult,
    parser_result: ProcessResult | None = None,
) -> TestCaseReport:
    """Builds report for unexpected interpreter exit code."""
    return TestCaseReport(
        result=TestResult.UNEXPECTED_INTERPRETER_EXIT_CODE,
        parser_exit_code=parser_result.returncode if parser_result else None,
        interpreter_exit_code=interpreter_result.returncode,
        parser_stdout=parser_result.stdout if parser_result else None,
        parser_stderr=parser_result.stderr if parser_result else None,
        interpreter_stdout=interpreter_result.stdout,
        interpreter_stderr=interpreter_result.stderr,
    )


def make_diff_fail_report(
    diff_result: ProcessResult,
    interpreter_result: ProcessResult,
    parser_result: ProcessResult | None = None,
) -> TestCaseReport:
    """Builds report for stdout mismatch validated by diff."""
    return TestCaseReport(
        result=TestResult.INTERPRETER_RESULT_DIFFERS,
        parser_exit_code=parser_result.returncode if parser_result else None,
        interpreter_exit_code=interpreter_result.returncode,
        parser_stdout=parser_result.stdout if parser_result else None,
        parser_stderr=parser_result.stderr if parser_result else None,
        interpreter_stdout=interpreter_result.stdout,
        interpreter_stderr=interpreter_result.stderr,
        diff_output=diff_result.stdout,
    )


def _write_temp_file(suffix: str, contents: str) -> Path:
    """Creates a temporary UTF-8 text file and returns its path."""
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=suffix, delete=False) as tmp:
        tmp.write(contents)
        return Path(tmp.name)


def _run_parser(
    test: LoadedTestCase,
    config: ExecutionConfig,
) -> tuple[ProcessResult, Path | None]:
    """Runs parser command for SOL source and returns parser output plus optional XML temp file."""
    source_path = _write_temp_file(".sol", test.parsed.source_code)
    try:
        parser_result = run_process([*config.parser_command, str(source_path)])
        xml_path: Path | None = None
        if parser_result.returncode == 0:
            xml_path = _write_temp_file(".xml", parser_result.stdout)
        return parser_result, xml_path
    finally:
        source_path.unlink(missing_ok=True)


def _run_interpreter(
    xml_path: Path,
    stdin_file: Path | None,
    config: ExecutionConfig,
) -> ProcessResult:
    """Runs interpreter on XML source with optional input file."""
    command = [*config.interpreter_command, "--source", str(xml_path)]
    if stdin_file is not None:
        command.extend(["--input", str(stdin_file)])
    return run_process(command)


def _validate_interpreter_stdout(
    test: LoadedTestCase,
    interpreter_result: ProcessResult,
    config: ExecutionConfig,
    parser_result: ProcessResult | None = None,
) -> TestCaseReport | None:
    """Validates interpreter stdout against expected .out file when required."""
    if interpreter_result.returncode != 0 or test.expected_stdout_file is None:
        return None

    diff_result = run_diff(test.expected_stdout_file, interpreter_result.stdout, config)
    if diff_result.returncode == 1:
        return make_diff_fail_report(diff_result, interpreter_result, parser_result)
    if diff_result.returncode != 0:
        raise RuntimeError(diff_result.stderr or diff_result.stdout or "diff failed")

    return None


def execute_parse_only(test: LoadedTestCase, config: ExecutionConfig) -> TestCaseReport:
    """Executes parse-only test case and validates parser exit code."""
    parser_result, xml_path = _run_parser(test, config)
    if xml_path is not None:
        xml_path.unlink(missing_ok=True)

    expected_codes = test.public_def.expected_parser_exit_codes or []
    if parser_result.returncode not in expected_codes:
        return make_parse_fail_report(parser_result)

    return make_pass_report(parser_result=parser_result)


def execute_execute_only(test: LoadedTestCase, config: ExecutionConfig) -> TestCaseReport:
    """Executes interpreter-only test case and validates exit code/stdout."""
    xml_path = _write_temp_file(".xml", test.parsed.source_code)
    try:
        interpreter_result = _run_interpreter(xml_path, test.stdin_file, config)
    finally:
        xml_path.unlink(missing_ok=True)

    expected_codes = test.public_def.expected_interpreter_exit_codes or []
    if interpreter_result.returncode not in expected_codes:
        return make_interpreter_fail_report(interpreter_result)

    diff_validation_report = _validate_interpreter_stdout(test, interpreter_result, config)
    if diff_validation_report is not None:
        return diff_validation_report

    return make_pass_report(interpreter_result=interpreter_result)


def execute_combined(test: LoadedTestCase, config: ExecutionConfig) -> TestCaseReport:
    """Executes parser then interpreter and validates both stages."""
    parser_result, xml_path = _run_parser(test, config)
    expected_parser_codes = test.public_def.expected_parser_exit_codes or [0]

    try:
        if parser_result.returncode not in expected_parser_codes:
            return make_parse_fail_report(parser_result)

        if xml_path is None:
            raise RuntimeError("Parser did not produce XML output.")

        interpreter_result = _run_interpreter(xml_path, test.stdin_file, config)
    finally:
        if xml_path is not None:
            xml_path.unlink(missing_ok=True)

    expected_interpreter_codes = test.public_def.expected_interpreter_exit_codes or []
    if interpreter_result.returncode not in expected_interpreter_codes:
        return make_interpreter_fail_report(interpreter_result, parser_result)

    diff_validation_report = _validate_interpreter_stdout(
        test,
        interpreter_result,
        config,
        parser_result,
    )
    if diff_validation_report is not None:
        return diff_validation_report

    return make_pass_report(parser_result, interpreter_result)


def execute_test_case(test: LoadedTestCase, config: ExecutionConfig) -> TestCaseReport:
    """Dispatches test execution by declared test type."""
    match test.public_def.test_type:
        case TestCaseType.PARSE_ONLY:
            return execute_parse_only(test, config)
        case TestCaseType.EXECUTE_ONLY:
            return execute_execute_only(test, config)
        case TestCaseType.COMBINED:
            return execute_combined(test, config)
        case _:
            raise ValueError(f"Unsupported test type: {test.public_def.test_type}")


def execute_selected_tests(
    selected_tests: list[LoadedTestCase],
    config: ExecutionConfig,
) -> tuple[
    dict[str, tuple[TestCaseDefinition, TestCaseReport]],
    dict[str, Exception],
    dict[str, Exception],
]:
    """Executes selected tests and partitions operational failures by category."""
    executed_results: dict[str, tuple[TestCaseDefinition, TestCaseReport]] = {}
    cannot_execute: dict[str, Exception] = {}
    other_errors: dict[str, Exception] = {}

    for test in selected_tests:
        try:
            executed_results[test.name] = (test.public_def, execute_test_case(test, config))
        except OSError as exc:
            cannot_execute[test.name] = exc
        except RECOVERABLE_EXECUTION_ERRORS as exc:
            other_errors[test.name] = exc
        except KeyboardInterrupt:
            raise

    return executed_results, cannot_execute, other_errors
