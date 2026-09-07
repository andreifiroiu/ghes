<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider vouched for a subject we have never seen and gave no address
 * to create an account with. Apple sends the email on the first sign-in
 * only, so this happens when that first link was lost; the user has to
 * revoke the app in their Apple ID settings and sign in again.
 */
class UnlinkableSocialIdentity extends RuntimeException {}
