<?php

namespace App\Licensing;

use RuntimeException;

/**
 * The Kiban Core did not answer (down, slow, network) or answered with an error.
 * The app keeps working with what it already has (last license, current sessions).
 */
class CoreUnavailableException extends RuntimeException {}
