<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $fillable = [
        'branch_id',
        'name',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'id');
    }

    public function assets()
    {
        return $this->hasMany(AssetLocation::class, 'location_id', 'id');
    }
	
	public function children()
    {
        return $this->hasMany(AssetLocation::class, 'location_id', 'id');
    }
}
