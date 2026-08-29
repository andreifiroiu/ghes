<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivitySurface;
use App\Enums\ActivityType;
use Database\Factories\UserActivityLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded user action.
 *
 * The generic counterpart to the purpose-built signal tables: reactions and
 * bookmarks keep their own rows (they carry the reversal ledger the profile
 * needs), and also appear here so that one query can read a user's whole
 * timeline — explicit and implicit signals together.
 *
 * @property ActivityType $type
 * @property ActivitySurface $surface
 * @property array<string, mixed> $context
 */
class UserActivityLog extends Model
{
    /** @use HasFactory<UserActivityLogFactory> */
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'event_id',
        'notification_id',
        'type',
        'surface',
        'session_key',
        'is_bot',
        'context',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'surface' => ActivitySurface::class,
            'is_bot' => 'boolean',
            'context' => 'array',
        ];
    }

    /**
     * Traffic that a person actually generated.
     *
     * Every rate we report and every score we rank on has to start here — a
     * digest whose links were prefetched by a mail scanner would otherwise show
     * a perfect click-through rate.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeHuman(Builder $query): Builder
    {
        return $query->where('is_bot', false);
    }

    /**
     * @param  Builder<$this>  $query
     * @param  ActivityType|list<ActivityType>  $type
     * @return Builder<$this>
     */
    public function scopeOfType(Builder $query, ActivityType|array $type): Builder
    {
        $types = is_array($type) ? $type : [$type];

        return $query->whereIn('type', array_map(
            fn (ActivityType $case): string => $case->value,
            $types,
        ));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
