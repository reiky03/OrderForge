<?php

namespace App\Models;

use Database\Factories\PaymentWebhookFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentWebhook extends Model
{
    /** @use HasFactory<PaymentWebhookFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['provider', 'external_event_id', 'payload', 'processed_at', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'immutable_datetime'];
    }
}
