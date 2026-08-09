<?php

namespace App\Exceptions;

use RuntimeException;

class DomainException extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
