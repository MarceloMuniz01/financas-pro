<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Import extends Model
{
    protected $fillable = [
    'user_id',
    'source',
    'filename',
    'original_filename',
    'file_hash',
    'bank',
    'status',
    'error_message',
    'processed_at',
    'error_message',
];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}