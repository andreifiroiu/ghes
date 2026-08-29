<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUserUpdateRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\DiscoveryLog;
use App\Models\User;
use App\Models\UserEventReaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $query = User::query()->withCount('reactions')->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search): void {
                $q->whereLike('name', "%{$search}%")
                    ->orWhereLike('email', "%{$search}%");
            });
        }

        $users = $query->paginate((int) config('eventpulse.pagination.admin_users', 20))->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => AdminUserResource::collection($users),
            'filters' => $request->only(['search']),
        ]);
    }

    public function show(User $user): Response
    {
        $reactionCounts = $user->reactions()
            ->get(['reaction'])
            ->countBy(fn (UserEventReaction $reaction) => $reaction->reaction->value);

        $resolvedDiscovery = DiscoveryLog::query()
            ->where('user_id', $user->id)
            ->whereNotNull('outcome')
            ->get(['outcome']);

        $resolvedCount = $resolvedDiscovery->count();
        $hits = $resolvedDiscovery->whereIn('outcome', DiscoveryLog::POSITIVE_OUTCOMES)->count();

        $recentReactions = $user->reactions()
            ->with('event:id,title')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (UserEventReaction $reaction) => [
                'reaction' => $reaction->reaction->value,
                'event_id' => $reaction->event_id,
                'event_title' => $reaction->event?->title,
                'created_at' => $reaction->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Users/Show', [
            'user' => new AdminUserResource($user->loadCount('reactions')),
            'insights' => [
                'interest_profile' => $user->interest_profile,
                'reactions_by_type' => $reactionCounts,
                'bookmarks' => $user->bookmarks()->count(),
                'discovery' => [
                    'openness' => (float) $user->discovery_openness,
                    'resolved' => $resolvedCount,
                    'hits' => $hits,
                    'hit_rate' => $resolvedCount > 0 ? round($hits / $resolvedCount, 4) : 0.0,
                ],
                'recent_reactions' => $recentReactions,
            ],
        ]);
    }

    public function update(AdminUserUpdateRequest $request, User $user): RedirectResponse
    {
        $user->update($request->validated());

        return redirect()->route('admin.users.show', $user)->with('success', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->is($user)) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $user->delete();

        return redirect()->route('admin.users.index')->with('success', 'User deleted.');
    }
}
