<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DiscoveryLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveryLog extends Model
{
    /** @use HasFactory<DiscoveryLogFactory> */
    use HasFactory, HasUuids;

    /**
     * Outcomes that count as a discovery "hit".
     *
     * `saved` is the bookmark signal rather than a Reaction case; it is stored
     * here verbatim so a bookmarked discovery event still counts as a success.
     *
     * `clicked` and `calendar` are the implicit signals: the user never said
     * anything, but they followed the link out to the source or put the event
     * in their calendar, which is a better answer to "was this exploration
     * worth making" than silence. It is a looser definition of
     * a hit than the two explicit signals, so the thresholds tuned against it —
     * `discovery.openness_hit_rate_threshold` and `discovery.suppression_threshold`
     * — are now measured on a larger numerator and want revisiting once there
     * is real click data behind them.
     *
     * @var list<string>
     */
    public const POSITIVE_OUTCOMES = ['interested', 'saved', 'clicked', 'calendar'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'event_id',
        'category_explored',
        'surprise_score',
        'outcome',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'surprise_score' => 'float',
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
