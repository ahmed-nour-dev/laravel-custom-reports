<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Exceptions;

/**
 * A saved or ad-hoc report definition the type, the registry or the rules
 * of SPEC §8.1 don't allow. Every case carries a stable slug for the SPA.
 */
final class InvalidReportDefinition extends ReportException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(string $message, private readonly string $slug, private readonly array $context = [])
    {
        parent::__construct($message);
    }

    public static function malformed(string $reason): self
    {
        return new self("The report definition is malformed: {$reason}", 'report_definition_malformed', ['reason' => $reason]);
    }

    public static function unsupportedVersion(mixed $version): self
    {
        return new self('Unsupported report definition version.', 'report_definition_version', ['version' => $version]);
    }

    public static function fieldNotInLayout(string $key): self
    {
        return new self("Field \"{$key}\" is not part of this report type.", 'report_definition_unknown_field', ['key' => $key]);
    }

    public static function operatorNotAllowed(string $key, string $operator): self
    {
        return new self("Operator \"{$operator}\" cannot be used on \"{$key}\".", 'report_definition_bad_operator', ['key' => $key, 'operator' => $operator]);
    }

    public static function badValue(string $key, string $reason): self
    {
        return new self("Bad filter value for \"{$key}\": {$reason}", 'report_definition_bad_value', ['key' => $key, 'reason' => $reason]);
    }

    public static function badLogic(string $reason): self
    {
        return new self("The filter logic is invalid: {$reason}", 'report_definition_bad_logic', ['reason' => $reason]);
    }

    public static function tooMany(string $what, int $count, int $max): self
    {
        return new self("{$count} {$what} is more than the allowed {$max}.", 'report_definition_too_many', ['what' => $what, 'count' => $count, 'max' => $max]);
    }

    public static function aggregateNotAllowed(string $key, string $function, string $reason): self
    {
        return new self("{$function} cannot be used on \"{$key}\": {$reason}", 'report_definition_bad_aggregate', ['key' => $key, 'function' => $function]);
    }

    public static function aggregateOnRepeatedField(string $key, string $function): self
    {
        return new self(
            "{$function} on \"{$key}\" would count it once per deeper row. Use count distinct, min or max.",
            'report_definition_aggregate_on_repeated',
            ['key' => $key, 'function' => $function],
        );
    }

    /** Rule 5b: a filter on a "may or may not have" level must be a top-level AND term. */
    public static function outerFilterInOr(string $key): self
    {
        return new self(
            "The filter on \"{$key}\" is on a related object that may be missing, so it can't be used under OR or NOT.",
            'outer_filter_in_or',
            ['key' => $key],
        );
    }

    public static function relatedOnInnerLevel(int $level): self
    {
        return new self("Level {$level} is \"must have\", so \"has / has no related\" doesn't apply to it.", 'report_definition_related_on_inner', ['level' => $level]);
    }

    public static function unknownChild(string $child): self
    {
        return new self("\"{$child}\" is not a related object of this report's primary object.", 'report_definition_unknown_child', ['child' => $child]);
    }

    public static function badBucket(string $key, string $reason): self
    {
        return new self("Bucket \"{$key}\" is invalid: {$reason}", 'report_definition_bad_bucket', ['key' => $key, 'reason' => $reason]);
    }

    /** A group sort, Top-N or detail-row setting that doesn't fit the report's groupings or aggregates. */
    public static function badGroupControl(string $what, string $reason): self
    {
        return new self("\"{$what}\" is invalid: {$reason}", 'report_definition_bad_group_control', ['what' => $what, 'reason' => $reason]);
    }

    /** A joined report block that doesn't fit (SPEC §14.7). $block counts from 1. */
    public static function badJoinedBlock(int $block, string $reason): self
    {
        return new self("Block {$block} is invalid: {$reason}", 'report_definition_bad_joined_block', ['block' => $block, 'reason' => $reason]);
    }

    /** The blocks' groupings don't reach the same registry source and field (SPEC §14.7). */
    public static function joinedNoCommonGrouping(string $reason): self
    {
        return new self("The blocks must group by the same field: {$reason}", 'report_definition_joined_no_common_grouping', ['reason' => $reason]);
    }

    /** The row formula doesn't parse, mixes levels, or is used where it can't be (SPEC §14.6, 9b). */
    public static function badRowFormula(string $reason): self
    {
        return new self("The row formula is invalid: {$reason}", 'report_definition_bad_row_formula', ['reason' => $reason]);
    }

    /** A summary formula that doesn't parse or doesn't fit the report (SPEC §14.6). $index counts from 0. */
    public static function badFormula(int $index, string $reason): self
    {
        $n = $index + 1;

        return new self("Formula {$n} is invalid: {$reason}", 'report_definition_bad_formula', ['formula' => $n, 'reason' => $reason]);
    }

    /** A chart that doesn't fit the report's groupings or aggregates (SPEC §14.5). */
    public static function badChart(string $type, string $reason): self
    {
        return new self("The {$type} chart is invalid: {$reason}", 'report_definition_bad_chart', ['type' => $type, 'reason' => $reason]);
    }

    /** A two-field comparison whose fields or operator don't fit (SPEC §14.3). */
    public static function badComparison(string $key, string $reason): self
    {
        return new self("The comparison on \"{$key}\" is invalid: {$reason}", 'report_definition_bad_comparison', ['key' => $key, 'reason' => $reason]);
    }

    /** Rule 5: a comparison touching an outer level must stay on that one level, in its ON. */
    public static function compareAcrossOuter(string $key, string $other): self
    {
        return new self(
            "\"{$key}\" and \"{$other}\" can't be compared: one is on a related object that may be missing, so both must be that object's own fields.",
            'report_definition_compare_across_outer',
            ['key' => $key, 'compare_field' => $other],
        );
    }

    /** `mine` / `my_team` on a primary object that has no owner. */
    public static function scopeNotAvailable(string $scope): self
    {
        return new self("\"{$scope}\" isn't available: this report's primary object has no owner.", 'report_definition_scope_not_available', ['scope' => $scope]);
    }

    public static function notYetSupported(string $what): self
    {
        return new self("{$what} are not available yet.", 'report_definition_not_supported', ['what' => $what]);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return $this->slug;
    }

    public function context(): array
    {
        return $this->context;
    }
}
