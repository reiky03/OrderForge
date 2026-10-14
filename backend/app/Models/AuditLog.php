<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['actor_type', 'actor_id', 'action', 'entity_type', 'entity_id', 'metadata', 'request_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['actor_id' => 'integer', 'metadata' => 'array'];
    }
}
