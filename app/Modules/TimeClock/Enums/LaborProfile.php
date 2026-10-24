<?php

namespace App\Modules\TimeClock\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Labor rules of the company's time clock.
 *
 * - Brazil (CLT): complete, ready for the official rules (Portaria 671: receipts, AFD/AEJ files, CPF and PIS);
 * - custom: other countries; the company states its workweek, overtime, night hours, tolerance and hour bank.
 *
 * Ready profiles for other countries can be added later.
 */
enum LaborProfile: string implements HasLabel
{
    case BrazilClt = 'br_clt';

    case Custom = 'custom';

    public function getLabel(): string
    {
        return __('timeclock.enums.labor_profile.'.$this->value);
    }

    public static function forCountry(string $country): self
    {
        return strtoupper($country) === 'BR' ? self::BrazilClt : self::Custom;
    }
}
