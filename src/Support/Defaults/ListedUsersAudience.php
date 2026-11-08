<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Support\Defaults;

use AhmedNour\CustomReports\Contracts\ReportAudience;
use AhmedNour\CustomReports\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The default audience: no groups, so folders aren't shared and a schedule
 * goes only to the users it lists. An app with roles or teams binds its own.
 */
final readonly class ListedUsersAudience implements ReportAudience
{
    public function groupIdsOf(Authenticatable $user): array
    {
        return [];
    }

    public function recipients(array $groupIds, array $userIds): iterable
    {
        if ($userIds === []) {
            return [];
        }

        $model = Users::model();
        /** @var iterable<Authenticatable> $users */
        $users = $model::query()->whereKey($userIds)->get();

        return $users;
    }
}
