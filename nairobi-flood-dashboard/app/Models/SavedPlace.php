<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedPlace extends Model
{
    public const MAX_PER_USER = 5;

    protected $fillable = ['user_id', 'name', 'lat', 'lng', 'subcounty'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float'];
    }
}
