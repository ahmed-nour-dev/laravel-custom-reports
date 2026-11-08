<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The app's groups of users — roles, teams, departments… — that folders are
 * shared with (`report_folder_shares.role_id`) and scheduled reports are
 * sent to (`report_subscriptions.role_ids`). The engine stores group ids
 * and asks the app what they mean.
 */
interface ReportAudience
{
    /**
     * The ids of every group $user belongs to.
     *
     * @return list<int>
     */
    public function groupIdsOf(Authenticatable $user): array;

    /**
     * The active users a scheduled report goes to: those listed, and the
     * members of the groups listed.
     *
     * @param  list<int>  $groupIds
     * @param  list<int>  $userIds
     * @return iterable<Authenticatable>
     */
    public function recipients(array $groupIds, array $userIds): iterable;
}
