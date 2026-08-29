<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Reaction;
use Database\Factories\UserEventReactionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's taste signal on an event.
 *
 * Bookmarks live in EventBookmark, not here — see App\Enums\Reaction.
 *
 * @property Reaction $reaction
 * @property ?array<string, float> $applied_deltas
 */
class UserEventReaction extends Model
{
    /** @use HasFactory<UserEventReactionFactory> */
    use HasFactory, HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'user_event_reactions';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'event_id',
        'reaction',
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
            'reaction' => Reaction::class,
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
