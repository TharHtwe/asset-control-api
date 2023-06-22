<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Asset extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'alternative_name',
        'group',
        'serial_no',
        'quantity',
        'photo',
        'details',
        'warranty_end',
        'status',
        'summarize_by_group',
        'summarize_by',
    ];

    protected $casts = [
        'warranty_end' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }

    public function movements()
    {
        return $this->hasMany(Movement::class, 'asset_id', 'id');
    }

    public function locations()
    {
        return $this->hasMany(AssetLocation::class, 'asset_id', 'id');
    }

    public function histories()
    {
        return $this->hasMany(AssetHistory::class, 'asset_id', 'id');
    }

    public function getPhotoAttribute($value)
    {
        return empty($value) ? null : Storage::disk(strtolower(config('asset.storage_disk')))->url($value);
    }
}
