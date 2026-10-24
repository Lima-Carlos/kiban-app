<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Brazilian CPF: 11 digits with the two check digits right (dots and dash are accepted).
 */
class Cpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! static::isValid((string) $value)) {
            $fail(__('app.validation.cpf'));
        }
    }

    public static function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    public static function isValid(string $value): bool
    {
        $cpf = (string) static::digits($value);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        foreach ([9, 10] as $length) {
            $sum = 0;

            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $cpf[$i] * ($length + 1 - $i);
            }

            $check = ($sum * 10) % 11 % 10;

            if ($check !== (int) $cpf[$length]) {
                return false;
            }
        }

        return true;
    }

    public static function format(?string $cpf): ?string
    {
        return $cpf !== null && strlen($cpf) === 11
            ? substr($cpf, 0, 3).'.'.substr($cpf, 3, 3).'.'.substr($cpf, 6, 3).'-'.substr($cpf, 9)
            : $cpf;
    }
}
