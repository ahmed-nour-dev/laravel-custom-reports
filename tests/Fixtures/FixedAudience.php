<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Tests\Fixtures;

use AhmedNour\CustomReports\Contracts\ReportAudience;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;

/** A different app's groups: departments, here a fixed user id → department ids map. */
final readonly class FixedAudience implements ReportAudience
{
    /**
     * @param  array<int, list<int>>  $departments
     */
    public function __construct(private array $departments) {}

    public function groupIdsOf(Authenticatable $user): array
    {
        return $this->departments[Users::id($user)] ?? [];
    }

    public function recipients(array $groupIds, array $userIds): iterable
    {
        $members = array_keys(array_filter($this->departments, static fn (array $groups): bool => array_intersect($groups, $groupIds) !== []));

        return User::query()->whereKey(array_values(array_unique([...$userIds, ...$members])))->get();
    }
}
