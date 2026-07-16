<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactAlias extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'contact_id',
        'name',
        'normalized_name',
    ];

    /**
     * Usuário dono do apelido.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class
        );
    }

    /**
     * Contato principal representado por este apelido.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(
            Contact::class
        );
    }
}