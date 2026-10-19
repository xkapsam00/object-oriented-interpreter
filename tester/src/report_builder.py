"""Builds final structured report from discovered and executed tests."""

from collections import defaultdict

from models import (
    CategoryReport,
    TestCaseDefinition,
    TestCaseReport,
    TestReport,
    TestResult,
    UnexecutedReason,
    UnexecutedReasonCode,
)
from soltest_parser import CannotDetermineTypeError


def build_final_report(
    discovered_test_cases: list[TestCaseDefinition],
    malformed: dict[str, Exception],
    filtered_out: list[TestCaseDefinition],
    executed_results: dict[str, tuple[TestCaseDefinition, TestCaseReport]],
    cannot_execute: dict[str, Exception] | None = None,
    other_errors: dict[str, Exception] | None = None,
) -> TestReport:
    """
    Aggregates discovered, filtered, malformed, and executed tests into final report model.

    executed_results:
        test_name -> (test_definition, test_case_report)
    """

    unexecuted: dict[str, UnexecutedReason] = {}

    for name, exc in malformed.items():
        reason_code = (
            UnexecutedReasonCode.CANNOT_DETERMINE_TYPE
            if isinstance(exc, CannotDetermineTypeError)
            else UnexecutedReasonCode.MALFORMED_TEST_CASE_FILE
        )
        unexecuted[name] = UnexecutedReason(code=reason_code, message=str(exc))

    for test in filtered_out:
        unexecuted[test.name] = UnexecutedReason(
            code=UnexecutedReasonCode.FILTERED_OUT,
            message="Filtered out by include/exclude rules.",
        )

    for name, exc in (cannot_execute or {}).items():
        unexecuted[name] = UnexecutedReason(
            code=UnexecutedReasonCode.CANNOT_EXECUTE,
            message=str(exc),
        )

    for name, exc in (other_errors or {}).items():
        unexecuted[name] = UnexecutedReason(
            code=UnexecutedReasonCode.OTHER,
            message=str(exc),
        )

    grouped_results: dict[str, dict[str, TestCaseReport]] = defaultdict(dict)
    category_points_total: dict[str, int] = defaultdict(int)
    category_points_passed: dict[str, int] = defaultdict(int)

    for test_name, (test_def, report) in executed_results.items():
        grouped_results[test_def.category][test_name] = report
        category_points_total[test_def.category] += test_def.points

        if report.result == TestResult.PASSED:
            category_points_passed[test_def.category] += test_def.points

    results = {
        category: CategoryReport(
            total_points=category_points_total[category],
            passed_points=category_points_passed[category],
            test_results=test_results,
        )
        for category, test_results in grouped_results.items()
    }

    return TestReport(
        discovered_test_cases=discovered_test_cases,
        unexecuted=unexecuted,
        results=results or None,
    )
