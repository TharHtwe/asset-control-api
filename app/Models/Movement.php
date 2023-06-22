<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Movement extends Model
{
    protected $table = 'asset_movements';
    protected $fillable = [
        'record_date',
        'asset_id',
        'location_id',
        'to_location_id',
        'movement_type',
        'quantity',
		'recorded_by',
        'remark',
    ];

    protected $casts = [
        'record_date' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id', 'id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id', 'id');
    }

    public function to_location()
    {
        return $this->belongsTo(Location::class, 'to_location_id', 'id');
    }
}
