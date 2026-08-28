<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventCategory;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;

/**
 * A canonical event, deduplicated across every provider that reported it.
 *
 * @property string $id
 * @property string|null $merged_into_id
 * @property string $title
 * @property string|null $description
 * @property string $source
 * @property string $source_url
 * @property string|null $source_id
 * @property string $fingerprint
 * @property string|null $match_key
 * @property EventCategory $category
 * @property array<int, string>|null $tags
 * @property string|null $venue
 * @property string|null $address
 * @property string|null $city
 * @property string|null $city_slug
 * @property float|null $latitude
 * @property float|null $longitude
 * @property Carbon|null $starts_at
 * @property Carbon|null $local_date
 * @property Carbon|null $ends_at
 * @property float|null $price_min
 * @property float|null $price_max
 * @property string $currency
 * @property bool $is_free
 * @property string|null $image_url
 * @property array<string, mixed>|null $metadata
 * @property int $popularity_score
 * @property int $sources_count
 * @property Carbon|null $last_seen_at
 * @property bool $is_classified
 * @property bool $is_geocoded
 * @property bool $is_enriched
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, HasUuids, Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'source',
        'source_url',
        'source_id',
        'fingerprint',
        'match_key',
        'merged_into_id',
        'category',
        'tags',
        'venue',
        'address',
        'city',
        'city_slug',
        'latitude',
        'longitude',
        'starts_at',
        'local_date',
        'ends_at',
        'price_min',
        'price_max',
        'currency',
        'is_free',
        'image_url',
        'metadata',
        'popularity_score',
        'sources_count',
        'last_seen_at',
        'is_classified',
        'is_geocoded',
        'is_enriched',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'metadata' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'local_date' => 'date',
            'last_seen_at' => 'datetime',
            'price_min' => 'float',
            'price_max' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
            'popularity_score' => 'integer',
            'sources_count' => 'integer',
            'is_free' => 'boolean',
            'is_classified' => 'boolean',
            'is_geocoded' => 'boolean',
            'is_enriched' => 'boolean',
            'category' => EventCategory::class,
        ];
    }

    /**
     * @return HasMany<UserEventReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(UserEventReaction::class);
    }

    /**
     * @return HasMany<DiscoveryLog, $this>
     */
    public function discoveryLogs(): HasMany
    {
        return $this->hasMany(DiscoveryLog::class);
    }

    /**
     * Every provider that reported this event.
     *
     * @return HasMany<EventSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(EventSource::class);
    }

    /**
     * The canonical event this one was merged into, if any.
     *
     * @return BelongsTo<Event, $this>
     */
    public function canonicalEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'merged_into_id');
    }

    /**
     * Events that were merged into this one.
     *
     * @return HasMany<Event, $this>
     */
    public function mergedDuplicates(): HasMany
    {
        return $this->hasMany(Event::class, 'merged_into_id');
    }

    /**
     * Scope to only include upcoming events.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>', now());
    }

    /**
     * Scope to only include canonical events, excluding any that have been
     * merged into another event.
     *
     * Deliberately an explicit scope rather than a global one: a global scope
     * would silently break findOrFail() inside the classification and geocoding
     * jobs, and route-model binding on links in already-sent digests.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeCanonical(Builder $query): Builder
    {
        return $query->whereNull('merged_into_id');
    }

    /**
     * Merged duplicates must not appear in search results.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->merged_into_id === null;
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category->value,
            'tags' => $this->tags,
            'city' => $this->city,
            'venue' => $this->venue,
        ];
    }
}
