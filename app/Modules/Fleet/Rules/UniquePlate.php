<?php

namespace App\Modules\Fleet\Rules;

use App\Modules\Fleet\Models\Vehicle;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The plate is not used by another vehicle of the company, whatever the format typed
 * ("ABC-1D23", "abc 1d23" and "ABC1D23" are the same plate).
 */
class UniquePlate implements ValidationRule
{
    public function __construct(protected ?Vehicle $ignore = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = Vehicle::query()
            ->where('plate', Vehicle::normalizePlate((string) $value))
            ->when($this->ignore?->exists, fn ($query) => $query->whereKeyNot($this->ignore->getKey()))
            ->exists();

        if ($exists) {
            $fail(__('fleet.errors.plate_taken'));
        }
    }
}
