<?php

namespace App\Exceptions;

use RuntimeException;

class ServiceUnderMaintenanceException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $maintenance
     */
    public function __construct(
        public readonly array $maintenance,
        ?string $message = null,
    ) {
        parent::__construct($message ?? 'This service is temporarily unavailable.');
    }
}
