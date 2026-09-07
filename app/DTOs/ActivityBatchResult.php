<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * What became of a reported batch, by cause, so the app can tell a clean
 * replay (everything a duplicate) from a broken one (everything dropped).
 */
final readonly class ActivityBatchResult
{
    /**
     * @param  int  $accepted  Rows written.
     * @param  int  $duplicates  Items whose id was already stored, or repeated within the batch.
     * @param  int  $dropped  Items naming an event that does not exist or is hidden.
     */
    public function __construct(
        public int $accepted,
        public int $duplicates,
        public int $dropped,
    ) {}
}
