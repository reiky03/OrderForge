<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $request_hash
 * @property string $status
 * @property int|null $response_status
 * @property array{data: array<string, mixed>, request_id: string|null}|null $response_body
 * @property CarbonImmutable $expires_at
 */
class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'key', 'request_hash', 'response_status', 'response_body', 'status', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
