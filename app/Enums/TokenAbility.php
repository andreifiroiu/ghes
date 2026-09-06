<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a Sanctum token is allowed to do.
 *
 * Access and refresh are separate tokens with disjoint abilities on purpose:
 * a stolen access token expires within the hour and cannot mint a new one,
 * and a refresh token cannot read anything.
 */
enum TokenAbility: string
{
    case AccessApi = 'api:access';
    case RefreshToken = 'token:refresh';
    /** Granted only when the `access-admin` gate allows the user. */
    case Admin = 'admin';
}
