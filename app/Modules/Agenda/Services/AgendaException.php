<?php

namespace App\Modules\Agenda\Services;

use RuntimeException;

/**
 * A rule of the Agenda was not met; the message is already translated for the person.
 */
class AgendaException extends RuntimeException {}
