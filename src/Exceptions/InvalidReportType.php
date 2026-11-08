<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Exceptions;

/**
 * A report type (or a field reference into one) that the registry or the
 * compiler cannot accept. Every case carries a stable slug for the SPA.
 */
final class InvalidReportType extends ReportException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        string $message,
        private readonly string $slug,
        private readonly array $context = [],
        private readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    public static function innerAfterOuter(int $level): self
    {
        return new self(
            "Level {$level} must be \"may or may not have\": an earlier level already is.",
            'report_type_inner_after_outer',
            ['level' => $level],
        );
    }

    public static function tooDeep(string $what, int $max): self
    {
        return new self("{$what} is deeper than the allowed {$max}.", 'report_type_too_deep', ['path' => $what, 'max' => $max]);
    }

    public static function unknownSource(string $key): self
    {
        return new self("Unknown report source or link \"{$key}\".", 'report_type_unknown_source', ['key' => $key]);
    }

    public static function invalidChain(int $level, string $reason): self
    {
        return new self("Level {$level} of the chain is invalid: {$reason}", 'report_type_invalid_chain', ['level' => $level]);
    }

    public static function unknownField(string $key): self
    {
        return new self("Unknown report field \"{$key}\".", 'report_type_unknown_field', ['key' => $key]);
    }

    public static function fieldCap(int $count, int $max): self
    {
        return new self("{$count} fields is more than the allowed {$max}.", 'report_type_field_cap', ['count' => $count, 'max' => $max]);
    }

    public static function duplicateField(string $key): self
    {
        return new self("Field \"{$key}\" is in the layout twice.", 'report_type_duplicate_field', ['key' => $key]);
    }

    /** Changing the chain changes the row grain, so a deployed type must be cloned instead (SPEC §5). */
    public static function chainLockedWhenDeployed(string $code): self
    {
        return new self(
            'A deployed report type\'s objects cannot change. Clone it, or move it back to development.',
            'report_type_chain_locked',
            ['code' => $code],
            409,
        );
    }

    /**
     * Saved reports store field keys by level (`l1.name`), so a new chain
     * would point them at another object's fields. Clone the type instead.
     *
     * @param  list<array{code: string|null, name: string}>  $reports
     */
    public static function chainInUse(string $code, array $reports): self
    {
        return new self(
            'Saved reports use this report type, so its objects cannot change. Clone it to build a different chain.',
            'report_type_chain_in_use',
            ['code' => $code, 'reports' => $reports],
            409,
        );
    }

    /**
     * @param  list<array{code: string|null, name: string}>  $reports
     */
    public static function inUse(string $code, array $reports): self
    {
        $names = implode(', ', array_map(static fn (array $report): string => $report['name'], array_slice($reports, 0, 5)));
        $more = count($reports) > 5 ? ' and '.(count($reports) - 5).' more' : '';

        return new self(
            "Saved reports use this report type ({$names}{$more}). Delete them first.",
            'report_type_in_use',
            ['code' => $code, 'reports' => $reports],
            409,
        );
    }

    /**
     * @param  list<string>  $problems
     */
    public static function notDeployable(array $problems): self
    {
        return new self('The report type cannot be deployed yet.', 'report_type_not_deployable', ['problems' => $problems]);
    }

    /**
     * @param  list<array{code: string|null, name: string}>  $reports
     * @param  list<string>  $keys
     */
    public static function fieldInUse(array $reports, array $keys): self
    {
        return new self(
            'Saved reports use fields this layout removes. Confirm to remove them from those reports.',
            'report_type_field_in_use',
            ['reports' => $reports, 'keys' => $keys],
            409,
        );
    }

    public function status(): int
    {
        return $this->httpStatus;
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
