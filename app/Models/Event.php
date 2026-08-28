<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventCategory;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;

/**
 * @property array<int, string>|null $tags
 * @property string|null $neighborhood
 * @property bool $is_hidden
 * @property EventCategory $category
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $created_at
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
        'category',
        'tags',
        'venue',
        'address',
        'city',
        'neighborhood',
        'latitude',
        'longitude',
        'starts_at',
        'ends_at',
        'price_min',
        'price_max',
        'currency',
        'is_free',
        'image_url',
        'metadata',
        'popularity_score',
        'is_classified',
        'is_geocoded',
        'is_enriched',
        'is_hidden',
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
            'price_min' => 'float',
            'price_max' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
            'popularity_score' => 'integer',
            'is_free' => 'boolean',
            'is_classified' => 'boolean',
            'is_geocoded' => 'boolean',
            'is_enriched' => 'boolean',
            'is_hidden' => 'boolean',
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
     * Scope to events not hidden by an admin.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    /**
     * Keep admin-hidden events out of the search index; Scout removes an
     * already-indexed record as soon as this turns false on save.
     */
    public function shouldBeSearchable(): bool
    {
        return ! $this->is_hidden;
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
