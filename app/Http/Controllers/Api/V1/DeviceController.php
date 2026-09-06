<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DeviceRegistrationRequest;
use App\Http\Resources\DeviceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Native push registrations. The web page keeps its own `push/subscribe`.
 */
class DeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::collection(DeviceResource::collection(
            $request->user()->devices()->orderByDesc('last_seen_at')->get(),
        ));
    }

    /**
     * Register or refresh this install's push token. Idempotent, and keyed
     * on the token alone: a second account signing in on the same handset
     * re-points the row, so the previous owner's digest stops landing here.
     */
    public function store(DeviceRegistrationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $device = Device::updateOrCreate(
            ['push_token' => $validated['push_token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'],
                'install_id' => $validated['install_id'] ?? null,
                'device_name' => $validated['device_name'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'os_version' => $validated['os_version'] ?? null,
                'locale' => $validated['locale'] ?? null,
                'timezone' => $validated['timezone'] ?? null,
                'last_seen_at' => now(),
            ],
        );

        return ApiResponse::item(new DeviceResource($device), $device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Forget this install's token, on sign-out. Scoped to the caller: a
     * token that belongs to another account is not theirs to remove.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_token' => ['required', 'string', 'max:255'],
        ]);

        $deleted = $request->user()->devices()->where('push_token', $validated['push_token'])->delete();

        if ($deleted === 0) {
            throw ValidationException::withMessages(['push_token' => ['No such device on this account.']]);
        }

        return ApiResponse::message('Device removed.');
    }
}
