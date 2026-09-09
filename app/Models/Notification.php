<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationFrequency;
use App\Enums\NotificationType;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property NotificationType $type
 * @property string|null $event_id
 * @property ?Event $event
 * @property int|null $lead_minutes
 * @property NotificationChannel $channel
 * @property NotificationFrequency $frequency
 * @property array<int, string> $event_ids
 * @property array<int, string> $discovery_event_ids
 * @property string|null $subject
 * @property Carbon|null $sent_at
 * @property Carbon|null $opened_at
 * @property Carbon|null $decay_applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory, HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'event_notifications';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'event_id',
        'lead_minutes',
        'channel',
        'frequency',
        'event_ids',
        'discovery_event_ids',
        'subject',
        'body_html',
        'sent_at',
        'opened_at',
        'decay_applied_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_ids' => 'array',
            'discovery_event_ids' => 'array',
            'lead_minutes' => 'integer',
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'decay_applied_at' => 'datetime',
            'type' => NotificationType::class,
            'channel' => NotificationChannel::class,
            'frequency' => NotificationFrequency::class,
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
     * The one event a reminder is about. Null on a digest, which carries its
     * events in `event_ids` instead.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Only digests.
     *
     * Every query written before reminders existed meant this, and several
     * still do — the admin open rate would read reminders as digests, and the
     * passive-decay sweep would punish a user for "ignoring" a reminder about
     * an event they had explicitly saved.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeDigests(Builder $query): Builder
    {
        return $query->where('type', NotificationType::Digest);
    }

    /**
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeReminders(Builder $query): Builder
    {
        return $query->where('type', NotificationType::Reminder);
    }
}
