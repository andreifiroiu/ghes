<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Paginated notification history (audit trail) for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::query()
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->limit(50)
            ->get();

        $items = $notifications->map(fn (Notification $notification) => [
            'id' => $notification->id,
            'channel' => $notification->channel->value,
            'subject' => $notification->subject,
            'event_ids' => $notification->event_ids,
            'discovery_event_ids' => $notification->discovery_event_ids,
            'sent_at' => $notification->sent_at,
            'opened_at' => $notification->opened_at,
            'created_at' => $notification->created_at,
        ])->all();

        return response()->json([
            'data' => $items,
            'total' => $notifications->count(),
        ]);
    }
}
