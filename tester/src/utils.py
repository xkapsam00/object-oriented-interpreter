"""Shared process execution helpers for tester modules."""

import subprocess
from dataclasses import dataclass


@dataclass(slots=True)
class ProcessResult:
    """A normalized subprocess output structure."""

    returncode: int
    stdout: str
    stderr: str


def run_process(cmd: list[str], stdin_text: str | None = None) -> ProcessResult:
    """
    Runs a subprocess command and returns normalized captured output.
    """
    proc = subprocess.run(
        cmd,
        input=stdin_text,
        text=True,
        capture_output=True,
        check=False,
    )
    return ProcessResult(
        returncode=proc.returncode,
        stdout=proc.stdout,
        stderr=proc.stderr,
    )
