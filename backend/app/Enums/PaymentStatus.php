<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';
    case Refunded = 'REFUNDED';
}
