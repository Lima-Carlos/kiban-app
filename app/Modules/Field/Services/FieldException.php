<?php

namespace App\Modules\Field\Services;

use RuntimeException;

/**
 * A Kiban Campo rule was broken (closed work order, technician of another company, plan limit...). The message
 * is translated and can be shown to the person as it is.
 */
class FieldException extends RuntimeException {}
