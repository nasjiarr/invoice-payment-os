<?php

namespace App\Exceptions;

use App\Enums\InvoiceStatus;
use Exception;

class InvalidStatusTransitionException extends Exception
{
    public function __construct(InvoiceStatus $from, InvoiceStatus $to)
    {
        parent::__construct("Cannot transition invoice status from '{$from->value}' to '{$to->value}'.");
    }
}
