<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OutboxEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $payload
 * @property string $event_type
 * @property int $attempts
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $published_at
 */
class OutboxEvent extends Model
{
    /** @use HasFactory<OutboxEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id', 'aggregate_type', 'aggregate_id', 'event_type', 'payload', 'status', 'attempts', 'available_at', 'published_at', 'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }
}
