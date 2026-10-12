<?php

namespace App\Licensing;

use RuntimeException;

/**
 * The Core refused the data (e.g. invalid mobile number). Holds the field errors to show on the form.
 */
class CoreValidationException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The Kiban Core refused the data.');
    }
}
