<?php

namespace App\People\Epi;

use Filament\Support\Contracts\HasLabel;

/**
 * Why an item came back: end of the work (or leaving the company), wear, damage, loss, end of the useful life.
 */
enum ReturnReason: string implements HasLabel
{
    case Finished = 'finished';

    case Wear = 'wear';

    case Damage = 'damage';

    case Loss = 'loss';

    case Expiry = 'expiry';

    case Other = 'other';

    public function getLabel(): string
    {
        return __('epi.return_reasons.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->getLabel()])->all();
    }

    /** The reason of the new delivery when an item is replaced for this reason. */
    public function deliveryReason(): DeliveryReason
    {
        return match ($this) {
            self::Wear => DeliveryReason::Wear,
            self::Damage => DeliveryReason::Damage,
            self::Loss => DeliveryReason::Loss,
            self::Expiry => DeliveryReason::Expiry,
            default => DeliveryReason::Other,
        };
    }

    /** What usually happens to the item: lost items never come back, worn or damaged ones are discarded. */
    public function defaultOutcome(bool $reusable): ReturnOutcome
    {
        return match ($this) {
            self::Loss => ReturnOutcome::Lost,
            self::Wear, self::Damage, self::Expiry => ReturnOutcome::Discard,
            default => $reusable ? ReturnOutcome::Stock : ReturnOutcome::Discard,
        };
    }
}
