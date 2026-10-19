"""Filtering utilities for selecting test cases."""

import re
from dataclasses import dataclass

from models import TestCaseDefinition


@dataclass(slots=True)
class FilterDecision:
    """Partitioned test lists after applying include/exclude filters."""

    selected: list[TestCaseDefinition]
    filtered_out: list[TestCaseDefinition]


@dataclass(slots=True)
class FilterArgs:
    """Preprocessed filter arguments, including precompiled regex patterns."""

    regex_filters: bool
    include: tuple[str, ...] | None
    include_category: tuple[str, ...] | None
    include_test: tuple[str, ...] | None
    exclude: tuple[str, ...] | None
    exclude_category: tuple[str, ...] | None
    exclude_test: tuple[str, ...] | None
    include_regex: tuple[re.Pattern[str], ...] | None
    include_category_regex: tuple[re.Pattern[str], ...] | None
    include_test_regex: tuple[re.Pattern[str], ...] | None
    exclude_regex: tuple[re.Pattern[str], ...] | None
    exclude_category_regex: tuple[re.Pattern[str], ...] | None
    exclude_test_regex: tuple[re.Pattern[str], ...] | None

    @classmethod
    def from_namespace(cls, args: object) -> FilterArgs:
        """Build typed filter settings from argparse namespace-like object."""

        include = _normalize_patterns(getattr(args, "include", None))
        include_category = _normalize_patterns(getattr(args, "include_category", None))
        include_test = _normalize_patterns(getattr(args, "include_test", None))
        exclude = _normalize_patterns(getattr(args, "exclude", None))
        exclude_category = _normalize_patterns(getattr(args, "exclude_category", None))
        exclude_test = _normalize_patterns(getattr(args, "exclude_test", None))
        regex_filters = bool(getattr(args, "regex_filters", False))

        return cls(
            regex_filters=regex_filters,
            include=include,
            include_category=include_category,
            include_test=include_test,
            exclude=exclude,
            exclude_category=exclude_category,
            exclude_test=exclude_test,
            include_regex=_compile_patterns(include, regex_filters),
            include_category_regex=_compile_patterns(include_category, regex_filters),
            include_test_regex=_compile_patterns(include_test, regex_filters),
            exclude_regex=_compile_patterns(exclude, regex_filters),
            exclude_category_regex=_compile_patterns(exclude_category, regex_filters),
            exclude_test_regex=_compile_patterns(exclude_test, regex_filters),
        )


def _matches_any_literal(value: str, patterns: tuple[str, ...] | None) -> bool:
    if patterns is None:
        return False
    return any(value == pattern for pattern in patterns)


def _matches_any_regex(value: str, patterns: tuple[re.Pattern[str], ...] | None) -> bool:
    if patterns is None:
        return False
    return any(pattern.search(value) is not None for pattern in patterns)


def _matches_any(
    value: str,
    patterns: tuple[str, ...] | None,
    compiled_patterns: tuple[re.Pattern[str], ...] | None,
    regex: bool,
) -> bool:
    if regex:
        return _matches_any_regex(value, compiled_patterns)
    return _matches_any_literal(value, patterns)


def _normalize_patterns(patterns: list[str] | None) -> tuple[str, ...] | None:
    if not patterns:
        return None

    normalized = [pattern.strip() for pattern in patterns if pattern.strip()]
    return tuple(normalized) or None


def _compile_patterns(
    patterns: tuple[str, ...] | None,
    regex_enabled: bool,
) -> tuple[re.Pattern[str], ...] | None:
    if not regex_enabled or patterns is None:
        return None

    return tuple(re.compile(pattern) for pattern in patterns)


def should_include(test: TestCaseDefinition, args: FilterArgs) -> bool:
    """Returns True when test passes include/exclude filtering rules."""
    includes_defined = any(
        [
            args.include,
            args.include_category,
            args.include_test,
        ]
    )

    if includes_defined:
        include_match = (
            _matches_any(test.name, args.include, args.include_regex, args.regex_filters)
            or _matches_any(test.category, args.include, args.include_regex, args.regex_filters)
            or _matches_any(
                test.category,
                args.include_category,
                args.include_category_regex,
                args.regex_filters,
            )
            or _matches_any(
                test.name,
                args.include_test,
                args.include_test_regex,
                args.regex_filters,
            )
        )
    else:
        include_match = True

    exclude_match = (
        _matches_any(test.name, args.exclude, args.exclude_regex, args.regex_filters)
        or _matches_any(test.category, args.exclude, args.exclude_regex, args.regex_filters)
        or _matches_any(
            test.category,
            args.exclude_category,
            args.exclude_category_regex,
            args.regex_filters,
        )
        or _matches_any(test.name, args.exclude_test, args.exclude_test_regex, args.regex_filters)
    )

    return include_match and not exclude_match


def partition_tests(test_defs: list[TestCaseDefinition], args: FilterArgs) -> FilterDecision:
    """Partitions tests into selected and filtered-out groups."""
    selected: list[TestCaseDefinition] = []
    filtered_out: list[TestCaseDefinition] = []

    for test in test_defs:
        if should_include(test, args):
            selected.append(test)
        else:
            filtered_out.append(test)

    return FilterDecision(selected=selected, filtered_out=filtered_out)
