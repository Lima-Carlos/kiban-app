<?php

namespace App\Modules\Pedidos\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Role of a person in the Pedidos. Owners and administrators of the company are always managers.
 */
enum PedidosRole: string implements HasLabel
{
    /** Everything: menu, settings, customers, orders, deliveries, the customers who stopped ordering. */
    case Manager = 'manager';

    /** Takes orders (phone, WhatsApp, counter), customers, deliveries and the customers who stopped ordering. */
    case Desk = 'desk';

    /** The kitchen: the board of orders (preparing, ready) and the ticket. */
    case Kitchen = 'kitchen';

    /** Delivers: the deliveries screen. */
    case Delivery = 'delivery';

    /** Only looks. */
    case Viewer = 'viewer';

    /** No access (default). */
    case None = 'none';

    public function getLabel(): string
    {
        return __('pedidos.enums.role.'.$this->value);
    }

    /**
     * @return array<int, self>
     */
    public static function assignable(): array
    {
        return [self::Desk, self::Kitchen, self::Delivery, self::Manager, self::Viewer];
    }

    /** Takes orders and registers customers. */
    public function takesOrders(): bool
    {
        return in_array($this, [self::Manager, self::Desk], true);
    }

    /** Moves orders on the board (preparing, ready). */
    public function works(): bool
    {
        return in_array($this, [self::Manager, self::Desk, self::Kitchen], true);
    }

    /** Sends and closes delivery runs. */
    public function delivers(): bool
    {
        return in_array($this, [self::Manager, self::Desk, self::Delivery], true);
    }
}
