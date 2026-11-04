<?php

namespace App\Modules\Expenses\Services;

use RuntimeException;

/** An expense rule was not met; the message says why, in the person's language. */
class ExpenseException extends RuntimeException {}
