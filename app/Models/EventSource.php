<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EventSourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One provider's report of an event.
 *
 * A canonical Event has one of these per provider that listed it. The unique
 * key (source, url_key, occurrence_key) is what makes re-scraping idempotent.
 */
class EventSource extends Model
{
    /** @use HasFactory<EventSourceFactory> */
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'source',
        'source_url',
        'url_key',
        'source_id',
        'occurrence_key',
        'title',
        'starts_at',
        'payload',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'starts_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
