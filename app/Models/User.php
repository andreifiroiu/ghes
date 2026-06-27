<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationFrequency;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property array<string, float> $interest_profile
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'interest_profile',
        'discovery_openness',
        'notification_channel',
        'notification_frequency',
        'timezone',
        'city',
        'onboarding_completed',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'interest_profile' => 'array',
            'discovery_openness' => 'float',
            'onboarding_completed' => 'boolean',
            'notification_channel' => NotificationChannel::class,
            'notification_frequency' => NotificationFrequency::class,
        ];
    }

    /**
     * Tags the user has suppressed via "hide like this".
     *
     * Stored in the interest profile as "negtag:{tag}" keys; used as a hard
     * filter to exclude matching events from recommendations and discovery.
     *
     * @return list<string>
     */
    public function negativeTags(): array
    {
        $tags = [];

        foreach ($this->interest_profile ?? [] as $key => $value) {
            if (str_starts_with((string) $key, 'negtag:')) {
                $tags[] = substr((string) $key, 7);
            }
        }

        return $tags;
    }

    /**
     * @return HasMany<UserEventReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(UserEventReaction::class);
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * @return HasMany<ChatMessage, $this>
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * @return HasMany<DiscoveryLog, $this>
     */
    public function discoveryLogs(): HasMany
    {
        return $this->hasMany(DiscoveryLog::class);
    }
}
