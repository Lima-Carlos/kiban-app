<?php

namespace App\Modules\TimeClock\Afd;

use RuntimeException;

/** The file is not an AFD of a time clock (the message is shown to the manager). */
class AfdImportException extends RuntimeException {}
