<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }
}
