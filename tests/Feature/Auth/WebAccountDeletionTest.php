<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->withoutVite());

it('renders the profile page the deletion form lives on', function () {
    // The form is unconditional markup in Dashboard/Profile; the behaviour it
    // drives is covered by the requests below.
    $this->actingAs(User::factory()->create())
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Profile')->has('user'));
});

it('deletes the account with the right password, signs out and lands on the home page', function () {
    $user = User::factory()->create();
    $user->createToken('phone');
    $user->bookmarks()->create(['event_id' => Event::factory()->create()->id]);

    $this->actingAs($user)
        ->from('/profile')
        ->delete('/account', ['current_password' => 'password'])
        ->assertRedirect(route('home'))
        ->assertSessionHas('success');

    $this->assertGuest();
    expect(User::whereKey($user->id)->exists())->toBeFalse()
        ->and(DB::table('event_bookmarks')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0);
});

it('refuses a wrong password and deletes nothing', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/profile')
        ->delete('/account', ['current_password' => 'not-it'])
        ->assertRedirect('/profile')
        ->assertSessionHasErrors(['current_password' => 'Parola nu este corectă.']);

    $this->assertAuthenticatedAs($user);
    expect(User::whereKey($user->id)->exists())->toBeTrue();
});

it('requires the password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/profile')->delete('/account', [])
        ->assertRedirect('/profile')
        ->assertSessionHasErrors('current_password');

    expect(User::whereKey($user->id)->exists())->toBeTrue();
});

it('throttles password guesses', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $i) {
        $this->actingAs($user)->delete('/account', ['current_password' => "guess-{$i}"])->assertRedirect();
    }

    $this->actingAs($user)->delete('/account', ['current_password' => 'password'])->assertStatus(429);
    expect(User::whereKey($user->id)->exists())->toBeTrue();
});

it('is not reachable signed out', function () {
    $this->delete('/account', ['current_password' => 'password'])->assertRedirect(route('login'));
});
