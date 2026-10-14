<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['event_id', 'event_type', 'payload', 'processed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'immutable_datetime'];
    }
}
