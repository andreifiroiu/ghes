<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Serialise every queued write to a user's `interest_profile`.
 *
 * Implementing jobs must expose the target user's id as `$this->userId`.
 */
trait SerializesProfileWrites
{
    /**
     * interest_profile is one JSON blob, so every job that mutates it for a
     * given user must be serialised against the others.
     *
     * shared() is load-bearing: without it WithoutOverlapping mixes the job
     * class into the lock key, so a job would only serialise against other
     * copies of itself and a bookmark job could still interleave with a
     * reaction job on the same profile.
     *
     * expireAfter is equally not optional: without it a worker killed mid-job
     * (deploy, OOM) holds the lock forever and that user's profile silently
     * stops updating. releaseAfter backs off instead of hot-looping, and the
     * implementing jobs set $tries generously enough that losing the lock a few
     * times cannot exhaust the attempts and drop the delta on the floor.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->userId))
                ->shared()
                ->releaseAfter(5)
                ->expireAfter(120),
        ];
    }
}
