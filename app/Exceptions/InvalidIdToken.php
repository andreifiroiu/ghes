<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An ID token from a social provider could not be verified. The message is
 * safe to show: it names the check that failed, never the token.
 */
class InvalidIdToken extends RuntimeException {}
