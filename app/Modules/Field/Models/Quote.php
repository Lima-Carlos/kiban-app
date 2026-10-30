<?php

namespace App\Modules\Field\Models;

use App\Models\User;
use App\Modules\Field\Enums\QuoteStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A quote for a customer: services and materials with prices, a discount, the validity date and the notes the
 * customer reads. It goes to the customer by a link (WhatsApp); the customer approves or declines on the phone
 * (the answer keeps who, when and where from), and the approved quote becomes a work order.
 * Changes go only through QuoteService.
 */
class Quote extends Model
{
    use BelongsToTenant;

    protected $table = 'fd_quotes';

    protected $guarded = ['*'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => QuoteStatus::class,
            'valid_until' => 'immutable_date',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'sent_at' => 'immutable_datetime',
            'viewed_at' => 'immutable_datetime',
            'answered_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<QuoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "Quote 000012". */
    public function label(): string
    {
        return __('field.quotes.label', ['number' => str_pad((string) $this->number, 6, '0', STR_PAD_LEFT)]);
    }

    public function subtotalCents(): int
    {
        return (int) $this->items->sum('total_cents');
    }

    /** Sent and past its date (the customer can no longer approve it). */
    public function isExpired(): bool
    {
        return $this->status === QuoteStatus::Sent && $this->valid_until->endOfDay()->isPast();
    }

    /** Can still be changed: only before the customer answers. */
    public function isEditable(): bool
    {
        return ! $this->status->isAnswered();
    }
}
