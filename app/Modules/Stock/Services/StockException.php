<?php

namespace App\Modules\Stock\Services;

use RuntimeException;

/** A stock rule was not met; the message says why, in the person's language. */
class StockException extends RuntimeException {}
