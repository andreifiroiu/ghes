<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScraperRunStatus;
use Database\Factories\ScraperRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One execution of one adapter against one city.
 *
 * @property string $id
 * @property string $source Adapter key, e.g. "iabilet".
 * @property string|null $city City key, e.g. "timisoara". Null on rows predating multi-city.
 * @property string|null $job_uuid Queue payload UUID; null for synchronous CLI runs.
 * @property ScraperRunStatus $status
 * @property int $events_found
 * @property int $events_created
 * @property int $events_updated
 * @property int $events_skipped
 * @property int $errors_count
 * @property array<int, mixed> $error_log
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ScraperRun extends Model
{
    /** @use HasFactory<ScraperRunFactory> */
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'source',
        'city',
        'job_uuid',
        'status',
        'events_found',
        'events_created',
        'events_updated',
        'events_skipped',
        'errors_count',
        'error_log',
        'started_at',
        'finished_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ScraperRunStatus::class,
            'error_log' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'events_found' => 'integer',
            'events_created' => 'integer',
            'events_updated' => 'integer',
            'events_skipped' => 'integer',
            'errors_count' => 'integer',
        ];
    }

    /**
     * How many runs have failed in a row for this source, counting back from
     * the most recent resolved one.
     *
     * Single definition on purpose. The orchestrator alerts on this and the
     * admin page displays it; when each computed its own, an in-flight run
     * between two failures broke the orchestrator's streak but not the page's,
     * so the page could claim an alert had fired that never did.
     *
     * In-flight runs are skipped rather than treated as a success — a run that
     * has not finished is not yet evidence of anything.
     */
    public static function consecutiveFailuresFor(string $source, ?string $city, int $limit = 50): int
    {
        $recent = static::query()
            ->where('source', $source)
            ->where('city', $city)
            ->whereIn('status', ScraperRunStatus::resolved())
            ->latest('started_at')
            ->limit($limit)
            ->get();

        $streak = 0;

        foreach ($recent as $run) {
            if ($run->status !== ScraperRunStatus::Failed) {
                break;
            }

            $streak++;
        }

        return $streak;
    }
}
