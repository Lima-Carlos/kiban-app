<?php

namespace App\Modules\Pedidos\Services;

use RuntimeException;

/**
 * A rule of the Pedidos was not met; the message is already translated for the person.
 */
class PedidosException extends RuntimeException {}
