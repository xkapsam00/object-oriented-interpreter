"""Test file discovery helpers for SOLtest inputs."""

from pathlib import Path

from models import TestCaseDefinitionFile


def discover_test_files(tests_dir: Path, recursive: bool) -> list[TestCaseDefinitionFile]:
    """
    Discovers .test files and associates optional sibling .in/.out files.
    The .test content itself is not parsed at this stage.
    """
    pattern = "**/*.test" if recursive else "*.test"
    discovered: list[TestCaseDefinitionFile] = []

    for test_path in sorted(tests_dir.glob(pattern)):
        stem = test_path.with_suffix("")
        stdin_file = stem.with_suffix(".in")
        stdout_file = stem.with_suffix(".out")

        discovered.append(
            TestCaseDefinitionFile(
                name=test_path.stem,
                test_source_path=test_path,
                stdin_file=stdin_file if stdin_file.exists() else None,
                expected_stdout_file=stdout_file if stdout_file.exists() else None,
            )
        )

    return discovered
