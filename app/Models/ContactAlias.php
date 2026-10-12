<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactAlias extends Model
{
    protected $fillable = [
        'user_id',
        'contact_id',
        'name',
        'normalized_name',
    ];

    /**
     * Usuário dono do apelido.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class
        );
    }

    /**
     * Contato principal representado por este apelido.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(
            Contact::class
        );
    }
}
