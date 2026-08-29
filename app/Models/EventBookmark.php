<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EventBookmarkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's bookmark ("Salvează") on an event.
 *
 * Deliberately separate from UserEventReaction: a bookmark and a taste signal
 * are independent, so a user can save an event and have an opinion about it at
 * the same time, and removing one never disturbs the other.
 *
 * @property ?array<string, float> $applied_deltas
 */
class EventBookmark extends Model
{
    /** @use HasFactory<EventBookmarkFactory> */
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'event_id',
        'applied_deltas',
        'is_processed',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'applied_deltas' => 'array',
            'is_processed' => 'boolean',
        ];
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
}
