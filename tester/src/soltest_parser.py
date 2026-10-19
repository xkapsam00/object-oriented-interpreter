"""Parsing and loading of SOLtest files into internal/public test models."""

from dataclasses import dataclass
from pathlib import Path

from models import TestCaseDefinition, TestCaseDefinitionFile, TestCaseType

RECOVERABLE_PARSE_ERRORS = (
    OSError,
    ValueError,
    TypeError,
    LookupError,
)

DESCRIPTION_PREFIX = "***"
CATEGORY_PREFIX = "+++"
PARSER_EXIT_PREFIX = "!C!"
INTERPRETER_EXIT_PREFIX = "!I!"
POINTS_PREFIX = ">>>"


class CannotDetermineTypeError(ValueError):
    """Raised when the metadata does not uniquely determine the test type."""


@dataclass(slots=True)
class ParsedSolTest:
    """Parsed metadata header and source code from a single .test file."""

    description: str | None
    category: str
    points: int
    parser_exit_codes: list[int]
    interpreter_exit_codes: list[int]
    source_code: str


@dataclass(slots=True)
class LoadedTestCase:
    """
    Internal execution representation of a discovered test case:
    - file metadata from discovery
    - parsed metadata header
    - raw source code
    - validated public model used in final report
    """

    name: str
    test_source_path: Path
    stdin_file: Path | None
    expected_stdout_file: Path | None
    parsed: ParsedSolTest
    public_def: TestCaseDefinition


def _split_test_file(contents: str) -> tuple[list[str], str]:
    """Splits .test file into metadata header lines and source code body."""
    lines = contents.splitlines()
    for index, line in enumerate(lines):
        if line.strip() == "":
            return lines[:index], "\n".join(lines[index + 1 :])

    raise ValueError("Missing empty line separating metadata from source code.")


def _parse_int_value(raw_value: str, field_name: str) -> int:
    """Parses non-empty integer metadata field or raises descriptive error."""
    value = raw_value.strip()
    if not value:
        raise ValueError(f"Missing value for {field_name}.")

    try:
        return int(value)
    except ValueError as exc:
        raise ValueError(f"Invalid integer value for {field_name}: {value}") from exc


def _source_is_xml(source_code: str) -> bool:
    """Detects whether test source body is already XML-based input."""
    stripped = source_code.lstrip()
    if not stripped:
        raise ValueError("Test source code is empty.")
    return stripped.startswith(("<?xml", "<program"))


def _determine_test_type(
    source_is_xml: bool,
    parser_exit_codes: list[int],
    interpreter_exit_codes: list[int],
) -> TestCaseType:
    """Determines test type from source format and declared expected exit codes."""
    if source_is_xml:
        if parser_exit_codes:
            raise CannotDetermineTypeError("XML-based tests cannot specify parser exit codes.")
        if not interpreter_exit_codes:
            raise CannotDetermineTypeError("XML-based tests must specify interpreter exit codes.")
        return TestCaseType.EXECUTE_ONLY

    if interpreter_exit_codes:
        if parser_exit_codes and parser_exit_codes != [0]:
            raise CannotDetermineTypeError(
                "Combined SOL tests may only specify parser exit code 0."
            )
        return TestCaseType.COMBINED

    if parser_exit_codes:
        return TestCaseType.PARSE_ONLY

    raise CannotDetermineTypeError("Cannot determine the test type from the provided metadata.")


def _parse_test_source(test_file: TestCaseDefinitionFile) -> ParsedSolTest:
    """Parses metadata header and source body from a .test file."""
    contents = test_file.test_source_path.read_text(encoding="utf-8")
    header_lines, source_code = _split_test_file(contents)

    description: str | None = None
    category: str | None = None
    points: int | None = None
    parser_exit_codes: list[int] = []
    interpreter_exit_codes: list[int] = []

    for line in header_lines:
        if line.startswith(DESCRIPTION_PREFIX):
            if description is not None:
                raise ValueError("Description may only be specified once.")
            description = line.removeprefix(DESCRIPTION_PREFIX).strip() or None
        elif line.startswith(CATEGORY_PREFIX):
            if category is not None:
                raise ValueError("Category may only be specified once.")
            category = line.removeprefix(CATEGORY_PREFIX).strip()
            if not category:
                raise ValueError("Category must not be empty.")
        elif line.startswith(PARSER_EXIT_PREFIX):
            parser_exit_codes.append(
                _parse_int_value(line.removeprefix(PARSER_EXIT_PREFIX), "parser exit code")
            )
        elif line.startswith(INTERPRETER_EXIT_PREFIX):
            interpreter_exit_codes.append(
                _parse_int_value(
                    line.removeprefix(INTERPRETER_EXIT_PREFIX), "interpreter exit code"
                )
            )
        elif line.startswith(POINTS_PREFIX):
            if points is not None:
                raise ValueError("Points may only be specified once.")
            points = _parse_int_value(line.removeprefix(POINTS_PREFIX), "points")
        elif line.strip():
            raise ValueError(f"Unknown metadata line: {line}")

    if category is None:
        raise ValueError("Missing mandatory category metadata.")
    if points is None:
        raise ValueError("Missing mandatory points metadata.")

    return ParsedSolTest(
        description=description,
        category=category,
        points=points,
        parser_exit_codes=parser_exit_codes,
        interpreter_exit_codes=interpreter_exit_codes,
        source_code=source_code,
    )


def _build_public_definition(
    test_file: TestCaseDefinitionFile,
    parsed: ParsedSolTest,
) -> TestCaseDefinition:
    """Builds validated public test definition model from parsed test data."""
    test_type = _determine_test_type(
        _source_is_xml(parsed.source_code),
        parsed.parser_exit_codes,
        parsed.interpreter_exit_codes,
    )

    return TestCaseDefinition(
        name=test_file.name,
        test_source_path=test_file.test_source_path,
        stdin_file=test_file.stdin_file,
        expected_stdout_file=test_file.expected_stdout_file,
        test_type=test_type,
        description=parsed.description,
        category=parsed.category,
        points=parsed.points,
        expected_parser_exit_codes=parsed.parser_exit_codes or None,
        expected_interpreter_exit_codes=parsed.interpreter_exit_codes or None,
    )


def load_test_definitions(
    discovered_files: list[TestCaseDefinitionFile],
) -> tuple[list[TestCaseDefinition], list[LoadedTestCase], dict[str, Exception]]:
    """Loads discovered files into validated test definitions and malformed-file errors."""
    discovered_test_cases: list[TestCaseDefinition] = []
    loaded_cases: list[LoadedTestCase] = []
    malformed: dict[str, Exception] = {}

    for test_file in discovered_files:
        try:
            parsed = _parse_test_source(test_file)
            public_def = _build_public_definition(test_file, parsed)
        except RECOVERABLE_PARSE_ERRORS as exc:
            existing = malformed.get(test_file.name)
            if existing is None:
                malformed[test_file.name] = exc
            else:
                malformed[test_file.name] = ValueError(
                    f"{existing}; {test_file.test_source_path}: {exc}"
                )
            continue
        except KeyboardInterrupt:
            raise
        discovered_test_cases.append(public_def)
        loaded_cases.append(
            LoadedTestCase(
                name=test_file.name,
                test_source_path=test_file.test_source_path,
                stdin_file=test_file.stdin_file,
                expected_stdout_file=test_file.expected_stdout_file,
                parsed=parsed,
                public_def=public_def,
            )
        )

    return discovered_test_cases, loaded_cases, malformed
