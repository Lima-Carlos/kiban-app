<?php

namespace App\Tenancy;

use RuntimeException;

/**
 * A company's data was read or written without knowing which company it is. This is a bug and must stop
 * the request: guessing could show one company's data to another.
 */
class MissingTenantException extends RuntimeException
{
    public function __construct(string $message = 'No company (tenant) selected for this operation.')
    {
        parent::__construct($message);
    }
}
