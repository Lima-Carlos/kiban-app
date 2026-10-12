<?php

namespace App\Modules\Fleet\Services;

use RuntimeException;

/**
 * A Frota rule was broken (odometer going back, plan limit, locked plate...). The message is translated
 * and can be shown to the person as it is.
 */
class FleetException extends RuntimeException {}
