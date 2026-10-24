<?php

namespace App\People;

use RuntimeException;

/**
 * A module refused what was asked in the "People" screen (e.g. plan limit of employees, CPF missing).
 * The message is translated and is shown to the person as it is; nothing is saved.
 */
class PersonRuleException extends RuntimeException {}
