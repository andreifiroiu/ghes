<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DeleteAccountRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Account\AccountDeleter;
use Illuminate\Http\JsonResponse;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountDeleter $deleter,
    ) {}

    /**
     * Delete the authenticated account, immediately and completely.
     *
     * Re-authentication by password is what stops a stolen access token
     * from destroying the account within its hour of validity.
     */
    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        $this->deleter->delete($request->user());

        return ApiResponse::message('Account deleted.');
    }
}
