<?php

namespace App\Enums;

enum PaymentEventStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored';
}
