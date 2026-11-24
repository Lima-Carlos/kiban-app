<?php

namespace App\Modules\Pedidos\Models;

use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Model;

/**
 * Pedidos settings of the company: the shop's address and point (where deliveries leave from), the suggested
 * delivery fee, the "come back" WhatsApp message and how the customers' rhythm is read.
 */
class PedidosSetting extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'pd_settings';

    protected $fillable = ['address', 'latitude', 'longitude', 'city', 'state', 'delivery_fee_cents', 'promo_message', 'late_percent', 'stopped_percent', 'min_orders', 'configured_at'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'delivery_fee_cents' => 'integer',
            'late_percent' => 'integer',
            'stopped_percent' => 'integer',
            'min_orders' => 'integer',
            'configured_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PedidosSetting $settings): void {
            // An empty field of the form is "no point", never 0,0.
            foreach (['latitude', 'longitude'] as $field) {
                if (($settings->getAttributes()[$field] ?? null) === '') {
                    $settings->setAttribute($field, null);
                }
            }
        });
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::create([])->refresh();
    }

    public function hasPoint(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** The message for customers who stopped ordering: the company's own, or the ready one. */
    public function promoMessage(): string
    {
        return filled($this->promo_message) ? (string) $this->promo_message : __('pedidos.messages.promo_default');
    }

    /** The company's time zone (from its license). */
    public static function timezone(): string
    {
        return app(TenantContext::class)->current()?->license()->tenant('timezone') ?: FilamentTimezone::get();
    }
}
