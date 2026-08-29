<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The command runs during migration, so legacy rows are seeded through the query
 * builder — the models and enum no longer accept the retired values.
 */
function seedLegacyReaction(User $user, Event $event, string $reaction): string
{
    $id = (string) Str::uuid();

    DB::table('user_event_reactions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'event_id' => $event->id,
        'reaction' => $reaction,
        'is_processed' => true,
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ]);

    return $id;
}

it('moves saved reactions into bookmarks, preserving when they were saved', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $id = seedLegacyReaction($user, $event, 'saved');

    $this->artisan('eventpulse:migrate-reaction-domain')->assertExitCode(0);

    expect(DB::table('user_event_reactions')->where('id', $id)->exists())->toBeFalse();

    $bookmark = DB::table('event_bookmarks')
        ->where('user_id', $user->id)
        ->where('event_id', $event->id)
        ->first();

    expect($bookmark)->not->toBeNull()
        // Already processed: the old delta is in the profile, re-applying it
        // would double-count.
        ->and((bool) $bookmark->is_processed)->toBeTrue()
        ->and($bookmark->applied_deltas)->toBeNull()
        ->and(Carbon\Carbon::parse($bookmark->created_at)->toDateString())
        ->toBe(now()->subDays(3)->toDateString());
});

it('collapses hidden reactions into not_interested', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $id = seedLegacyReaction($user, $event, 'hidden');

    $this->artisan('eventpulse:migrate-reaction-domain');

    expect(DB::table('user_event_reactions')->where('id', $id)->value('reaction'))
        ->toBe('not_interested');
});

it('deletes retired link_opened rows', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $id = seedLegacyReaction($user, $event, 'link_opened');

    $this->artisan('eventpulse:migrate-reaction-domain');

    expect(DB::table('user_event_reactions')->where('id', $id)->exists())->toBeFalse();
});

it('rewrites legacy discovery outcomes', function () {
    $user = User::factory()->create();

    foreach (['hidden', 'link_opened'] as $outcome) {
        DB::table('discovery_logs')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'event_id' => Event::factory()->create()->id,
            'category_explored' => 'music',
            'surprise_score' => 0.5,
            'outcome' => $outcome,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->artisan('eventpulse:migrate-reaction-domain');

    expect(DB::table('discovery_logs')->where('outcome', 'hidden')->count())->toBe(0)
        ->and(DB::table('discovery_logs')->where('outcome', 'not_interested')->count())->toBe(1)
        ->and(DB::table('discovery_logs')->where('outcome', 'link_opened')->count())->toBe(0)
        ->and(DB::table('discovery_logs')->whereNull('outcome')->count())->toBe(1);
});

it('strips negative tags while leaving real interest scores alone', function () {
    $user = User::factory()->create();

    DB::table('users')->where('id', $user->id)->update([
        'interest_profile' => json_encode([
            'music' => 0.8,
            'tag:jazz' => 0.6,
            'negtag:techno' => 1.0,
            'negtag:crowded' => 1.0,
        ]),
    ]);

    $this->artisan('eventpulse:migrate-reaction-domain');

    $profile = $user->fresh()->interest_profile;

    expect($profile)->toHaveKey('music')
        ->and($profile)->toHaveKey('tag:jazz')
        ->and($profile['music'])->toEqualWithDelta(0.8, 0.0001)
        ->and(array_keys($profile))->not->toContain('negtag:techno')
        ->and(array_keys($profile))->not->toContain('negtag:crowded');
});

it('is safe to run twice', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    seedLegacyReaction($user, $event, 'saved');

    DB::table('users')->where('id', $user->id)->update([
        'interest_profile' => json_encode(['music' => 0.8, 'negtag:techno' => 1.0]),
    ]);

    $this->artisan('eventpulse:migrate-reaction-domain');
    $this->artisan('eventpulse:migrate-reaction-domain')->assertExitCode(0);

    expect(DB::table('event_bookmarks')->where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()->interest_profile['music'])->toEqualWithDelta(0.8, 0.0001);
});
