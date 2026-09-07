<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialProvider;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider account linked to a Ghes account.
 *
 * @property string $id
 * @property string $user_id
 * @property SocialProvider $provider
 * @property string $subject
 * @property string|null $email
 */
class SocialIdentity extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'provider', 'subject', 'email'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['provider' => SocialProvider::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
