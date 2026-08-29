<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of one scraper run.
 *
 * A run starts as Running and is resolved exactly once, to Completed or
 * Failed. A row left as Running is not a fourth state — it means the worker
 * died mid-run and never got to resolve it.
 */
enum ScraperRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Has this run finished, either way? Rates and averages are only meaningful
     * over resolved runs — an in-flight run is not yet a success or a failure.
     *
     * @return list<self>
     */
    public static function resolved(): array
    {
        return [self::Completed, self::Failed];
    }
}
