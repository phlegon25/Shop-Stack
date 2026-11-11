<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicketReply extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ticket_id', 'author_type', 'author_id', 'author_name', 'body', 'is_internal'];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'created_at'  => 'datetime',
        ];
    }
}
