<?php

declare(strict_types=1);

namespace App\Services\InterestProfile;

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProfileUpdater
{
    /**
     * Update a user's interest profile based on a signal about an event.
     *
     * Thin wrapper over deltaKeysFor() + apply(), kept for callers that do not
     * need the reversal ledger (notably the passive "ignored" decay).
     *
     * @return array<string, float> the effective, post-clamp change per profile key
     */
    public function updateFromFeedback(User $user, Event $event, string $signal, bool $isDiscovery = false): array
    {
        return $this->apply($user, $this->deltaKeysFor($event, $signal, $isDiscovery));
    }

    /**
     * Map a signal ("interested", "saved", "not_interested", "ignored") onto the
     * profile keys it touches for this event.
     *
     * Returns the event's category key and one "tag:{tag}" key per tag, each
     * carrying the configured delta scaled for discovery. An unknown signal, or
     * one configured with a zero delta, contributes nothing.
     *
     * @return array<string, float>
     */
    public function deltaKeysFor(Event $event, string $signal, bool $isDiscovery = false): array
    {
        /** @var array<string, array{category: float, tag: float}> $deltas */
        $deltas = config('eventpulse.feedback.deltas');
        $delta = $deltas[$signal] ?? null;

        if ($delta === null) {
            // Not a data condition: it means a signal name and the config map
            // have drifted apart, and the whole signal would silently stop
            // affecting profiles with every downstream call still reporting success.
            Log::error('ProfileUpdater: no configured delta for signal', [
                'signal' => $signal,
                'configured' => array_keys($deltas),
                'event_id' => $event->id,
            ]);

            return [];
        }

        $categoryDelta = $this->scaleForDiscovery((float) $delta['category'], $isDiscovery);
        $tagDelta = $this->scaleForDiscovery((float) $delta['tag'], $isDiscovery);

        $keyDeltas = [];

        if ($categoryDelta !== 0.0) {
            $keyDeltas[$event->category->value] = $categoryDelta;
        }

        if ($tagDelta !== 0.0) {
            foreach ($event->tags ?? [] as $tag) {
                $keyDeltas["tag:{$tag}"] = $tagDelta;
            }
        }

        return $keyDeltas;
    }

    /**
     * Add the given per-key deltas to the user's profile, clamped to [0.0, 1.0].
     *
     * Returns the change that was *actually* applied per key, which is not the
     * requested delta when a score hits a clamp boundary. Callers persist that
     * map so revert() can undo exactly what happened.
     *
     * @param  array<string, float>  $keyDeltas
     * @return array<string, float>
     */
    public function apply(User $user, array $keyDeltas): array
    {
        if ($keyDeltas === []) {
            return [];
        }

        return $this->mutate($user, function (array $profile) use ($keyDeltas): array {
            $applied = [];

            foreach ($keyDeltas as $key => $delta) {
                $current = (float) ($profile[$key] ?? 0.0);
                $next = $this->clampScore($current + $delta);

                if ($next !== $current) {
                    $applied[$key] = $next - $current;
                }

                $profile[$key] = $next;
            }

            return [$profile, $applied];
        });
    }

    /**
     * Subtract a previously-applied delta map from the user's profile.
     *
     * @param  array<string, float>  $appliedDeltas
     */
    public function revert(User $user, array $appliedDeltas): void
    {
        if ($appliedDeltas === []) {
            return;
        }

        $this->mutate($user, function (array $profile) use ($appliedDeltas): array {
            foreach ($appliedDeltas as $key => $delta) {
                $current = (float) ($profile[$key] ?? 0.0);
                $profile[$key] = $this->clampScore($current - (float) $delta);
            }

            return [$profile, []];
        });
    }

    /**
     * Read-modify-write the profile under a row lock.
     *
     * interest_profile is a single JSON blob written by queued jobs, so
     * concurrent reactions from one user would otherwise clobber each other.
     *
     * @param  callable(array<string, mixed>): array{0: array<string, mixed>, 1: array<string, float>}  $mutator
     * @return array<string, float>
     */
    private function mutate(User $user, callable $mutator): array
    {
        return DB::transaction(function () use ($user, $mutator): array {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            [$profile, $applied] = $mutator($locked->interest_profile ?? []);

            $locked->update(['interest_profile' => $profile]);

            // Refresh the caller's instance, but sync it clean. Leaving
            // interest_profile dirty means the next $user->update() on any other
            // column flushes this snapshot too — outside the lock — silently
            // reverting whatever a concurrent job wrote in between.
            $user->setAttribute('interest_profile', $profile);
            $user->syncOriginalAttribute('interest_profile');

            return $applied;
        });
    }

    /**
     * Amplify positive / soften negative deltas for discovery-event reactions.
     */
    private function scaleForDiscovery(float $delta, bool $isDiscovery): float
    {
        if (! $isDiscovery || $delta === 0.0) {
            return $delta;
        }

        $multiplier = $delta > 0.0
            ? (float) config('eventpulse.discovery.reward_multiplier', 1.5)
            : (float) config('eventpulse.discovery.penalty_multiplier', 0.5);

        return $delta * $multiplier;
    }

    /**
     * Clamp a score to the valid range [0.0, 1.0].
     */
    public function clampScore(float $score): float
    {
        return max(0.0, min(1.0, $score));
    }
}
