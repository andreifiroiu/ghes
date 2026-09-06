<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Machine-readable error codes for the versioned API.
 *
 * The client switches on `error.code`, never on the message (which is prose
 * and may be localised) and never on the HTTP status alone (a 401 for an
 * expired token and a 401 for a revoked one call for different recovery).
 */
enum ApiErrorCode: string
{
    case ValidationFailed = 'validation_failed';
    case Unauthenticated = 'unauthenticated';
    /** Reserved for the access/refresh token lifecycle; not emitted yet. */
    case TokenExpired = 'token_expired';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case RateLimited = 'rate_limited';
    case UpgradeRequired = 'upgrade_required';
    case BadRequest = 'bad_request';
    case ServiceUnavailable = 'service_unavailable';
    case ServerError = 'server_error';

    /**
     * The code an otherwise-unmapped HTTP status reads as, so a policy denial
     * rendered with a custom status, a 405 or a maintenance 503 still tells
     * the client what kind of problem it has.
     */
    public static function forStatus(int $status): self
    {
        return match (true) {
            $status === 403 => self::Forbidden,
            $status === 404 => self::NotFound,
            $status === 426 => self::UpgradeRequired,
            $status === 429 => self::RateLimited,
            $status === 503 => self::ServiceUnavailable,
            $status >= 500 => self::ServerError,
            default => self::BadRequest,
        };
    }
}
