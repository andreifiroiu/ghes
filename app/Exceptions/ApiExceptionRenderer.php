<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ApiErrorCode;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Renders every exception raised under `/api` as the API error envelope.
 *
 * Scoped by path, deliberately. The tempting `$request->expectsJson()` guard
 * would also capture the JSON the web frontend consumes — `FeedbackController`,
 * `BookmarkController` and `ChatController` all answer `fetch()` calls from
 * `resources/js` — and would silently reshape responses that code already
 * parses. Anything outside `/api` returns null here and falls through to the
 * framework's default rendering, untouched. Nothing but the versioned API
 * and its 410 catch-all lives under `/api` (Horizon and the log viewer sit
 * on their own prefixes), so the whole prefix is safe to claim.
 */
final class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?Response
    {
        // Both forms: `api/*` alone would leave a bare `/api` on the
        // framework's default JSON, one shape away from every other API error.
        if (! $request->is('api', 'api/*')) {
            return null;
        }

        return match (true) {
            // The framework's own escape hatch for "respond with exactly
            // this". Render callbacks run before the handler's own arm for
            // it, so without this the carried response would be discarded
            // and reported as a server error.
            $e instanceof HttpResponseException => $e->getResponse(),
            $e instanceof ValidationException => ApiResponse::error(
                ApiErrorCode::ValidationFailed,
                $e->getMessage(),
                $e->status,
                $e->errors(),
            ),
            $e instanceof AuthenticationException => $this->unauthenticated($request),
            // A gate's own deny message survives; only the empty default is
            // replaced.
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => ApiResponse::error(
                ApiErrorCode::Forbidden,
                $e->getMessage() !== '' ? $e->getMessage() : 'This action is unauthorized.',
                403,
            ),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiResponse::error(
                ApiErrorCode::NotFound,
                'Not found.',
                404,
            ),
            $e instanceof TooManyRequestsHttpException => $this->rateLimited($e),
            $e instanceof HttpExceptionInterface => $this->http($e),
            default => $this->serverError($e),
        };
    }

    /**
     * An expired token gets its own code: the client answers it with a
     * refresh, whereas a revoked or unknown one means signing in again.
     * The lookup is the same one the guard just did, so it costs one query
     * only on the failure path.
     */
    private function unauthenticated(Request $request): JsonResponse
    {
        $bearer = $request->bearerToken();

        // Only for a bearer shaped the way the guard would have looked up: a
        // non-numeric id before the pipe is a type error against Postgres's
        // bigint column, and the guard refuses those before querying.
        if ($bearer !== null && $this->isWellFormedBearer($bearer)) {
            /** @var class-string<PersonalAccessToken> $model */
            $model = Sanctum::$personalAccessTokenModel;
            $token = $model::findToken($bearer);

            // Only an expired *access* token means "refresh": an expired
            // refresh token must read as "sign in again", or a client
            // following the contract would loop on refresh forever.
            if ($token instanceof \App\Models\PersonalAccessToken
                && $token->name === \App\Models\PersonalAccessToken::NAME_ACCESS
                && $token->expires_at !== null
                && $token->expires_at->isPast()) {
                return ApiResponse::error(ApiErrorCode::TokenExpired, 'Token expired.', 401);
            }
        }

        return ApiResponse::error(ApiErrorCode::Unauthenticated, 'Unauthenticated.', 401);
    }

    /**
     * Sanctum's own precondition for `id|token`: the id part must be digits.
     */
    private function isWellFormedBearer(string $bearer): bool
    {
        if (! str_contains($bearer, '|')) {
            return true;
        }

        [$id] = explode('|', $bearer, 2);

        return ctype_digit($id);
    }

    private function rateLimited(TooManyRequestsHttpException $e): JsonResponse
    {
        $headers = $e->getHeaders();
        $retryAfter = isset($headers['Retry-After']) ? (int) $headers['Retry-After'] : null;

        return ApiResponse::error(
            ApiErrorCode::RateLimited,
            'Too many requests.',
            429,
            extra: ['retry_after' => $retryAfter],
            headers: $headers,
        );
    }

    /**
     * Any other HTTP exception keeps its status and headers, with the code
     * derived from the status. That covers a policy denial rendered with a
     * custom status, a 405, and — the one that matters operationally — the
     * 503 that `artisan down --retry=N` raises from the global middleware
     * stack: it must keep its `Retry-After` and must not read as a crash.
     */
    private function http(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $code = ApiErrorCode::forStatus($status);
        $headers = $e->getHeaders();

        $message = match (true) {
            $e->getMessage() !== '' => $e->getMessage(),
            $status === 503 => 'Service temporarily unavailable.',
            $status >= 500 => 'Server error.',
            default => 'Bad request.',
        };

        $extra = isset($headers['Retry-After']) ? ['retry_after' => (int) $headers['Retry-After']] : [];

        return ApiResponse::error($code, $message, $status, extra: $extra, headers: $headers);
    }

    /**
     * Reporting is untouched — the handler logs the exception before it
     * renders — so only the response body is shaped here. The real message is
     * exposed only with debug on, exactly as the framework's HTML page does.
     */
    private function serverError(Throwable $e): JsonResponse
    {
        $debug = (bool) config('app.debug');

        return ApiResponse::error(
            ApiErrorCode::ServerError,
            $debug ? $e->getMessage() : 'Server error.',
            500,
            $debug ? ['exception' => $e::class] : null,
        );
    }
}
