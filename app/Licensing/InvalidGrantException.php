<?php

namespace App\Licensing;

use RuntimeException;

/**
 * The Core refused a sign-in code or a refresh token (expired, already used, revoked).
 */
class InvalidGrantException extends RuntimeException {}
