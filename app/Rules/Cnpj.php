<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Brazilian CNPJ: 14 digits with the two check digits right (dots, slash and dash are accepted).
 */
class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! static::isValid((string) $value)) {
            $fail(__('app.validation.cnpj'));
        }
    }

    public static function isValid(string $value): bool
    {
        $cnpj = (string) preg_replace('/\D/', '', $value);

        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        foreach ([12, 13] as $length) {
            $sum = 0;
            $weight = $length - 7;

            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $cnpj[$i] * $weight;
                $weight = $weight === 2 ? 9 : $weight - 1;
            }

            $check = $sum % 11 < 2 ? 0 : 11 - $sum % 11;

            if ($check !== (int) $cnpj[$length]) {
                return false;
            }
        }

        return true;
    }
}
