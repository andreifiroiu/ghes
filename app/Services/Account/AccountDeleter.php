<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Hard-deletes an account and everything that is only meaningful with it.
 *
 * Immediate, with no grace period: the stores accept that, and a grace
 * period would need a whole reconsent flow. Reactions, bookmarks, chat,
 * notifications, discovery and activity logs cascade from the users row;
 * tokens (a morph relation) and sessions (no foreign key) do not, and are
 * removed explicitly first so nothing is orphaned. Engagement counts shift
 * slightly when a user's activity rows go, but `events.engagement_score`
 * is a persisted aggregate, so ranking does not forget.
 */
class AccountDeleter
{
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();

            DB::table('sessions')->where('user_id', $user->id)->delete();

            // Cascades on Postgres, but explicit here so the sqlite test
            // connection and a future driver without the constraint agree.
            $user->pushSubscriptions()->delete();

            $user->delete();
        });
    }
}
