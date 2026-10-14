<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $payload
 * @property string $event_type
 * @property string $status
 * @property CarbonImmutable|null $retried_at
 */
class DeadLetter extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = ['event_id', 'event_type', 'payload', 'attempts', 'failure_reason', 'status', 'failed_at', 'retried_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'attempts' => 'integer', 'failed_at' => 'immutable_datetime', 'retried_at' => 'immutable_datetime'];
    }
}
