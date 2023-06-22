<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $fillable = [
        'name',
        'logo',
        'phone',
        'address',
    ];

    public function assets()
    {
        return $this->hasMany(Asset::class, 'organization_id', 'id');
    }
}
