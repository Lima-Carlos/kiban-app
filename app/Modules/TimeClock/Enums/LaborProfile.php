<?php

namespace App\Modules\TimeClock\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Labor rules of the company's time clock.
 *
 * - Brazil (CLT): complete, ready for the official rules (Portaria 671: receipts, AFD/AEJ files, CPF and PIS);
 * - Mexico (LFT): the Federal Labor Law's floors (double pay for overtime, triple on rest days and holidays,
 *   night work from 20:00 to 06:00, no hour bank) and Mexico's national holidays, calculated;
 * - custom: other countries; the company states its workweek, overtime, night hours, tolerance and hour bank.
 */
enum LaborProfile: string implements HasLabel
{
    case BrazilClt = 'br_clt';

    case MexicoLft = 'mx_lft';

    case Custom = 'custom';

    public function getLabel(): string
    {
        return __('timeclock.enums.labor_profile.'.$this->value);
    }

    public static function forCountry(string $country): self
    {
        return match (strtoupper($country)) {
            'BR' => self::BrazilClt,
            'MX' => self::MexicoLft,
            default => self::Custom,
        };
    }

    /**
     * The law's values: percentages are floors (the company may only pay more); night hours are fixed.
     *
     * @return array<string, int|string>
     */
    public function legal(): array
    {
        return match ($this) {
            self::BrazilClt => ['overtime_percent' => 50, 'rest_day_overtime_percent' => 100, 'night_start' => '22:00', 'night_end' => '05:00', 'night_percent' => 20, 'tolerance_punch_minutes' => 5, 'tolerance_day_minutes' => 10],
            // LFT art. 60 (night 20:00-06:00), 67-68 (overtime paid double; beyond 9 hours a week, triple),
            // 73 and 75 (rest day and mandatory holidays worked: double pay on top of the normal day).
            self::MexicoLft => ['overtime_percent' => 100, 'rest_day_overtime_percent' => 200, 'night_start' => '20:00', 'night_end' => '06:00', 'night_percent' => 0],
            self::Custom => [],
        };
    }

    /** Values a company gets when it chooses this profile (the law's, with no hour bank where the law has none). */
    public function defaults(): array
    {
        return match ($this) {
            self::BrazilClt => $this->legal() + ['night_reduced_hour' => true],
            self::MexicoLft => $this->legal() + ['night_reduced_hour' => false, 'hour_bank' => false, 'tolerance_punch_minutes' => 0, 'tolerance_day_minutes' => 0],
            self::Custom => [],
        };
    }

    /** Night hours set by the law (the company cannot change them). */
    public function fixedNight(): bool
    {
        return $this !== self::Custom;
    }

    /** Whether the law allows an hour bank (Mexico: no; overtime is always paid). */
    public function allowsHourBank(): bool
    {
        return $this !== self::MexicoLft;
    }
}
