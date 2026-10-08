<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_token',
        'mac_address',
        'name',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function telemetryLogs()
    {
        return $this->hasMany(TelemetryLog::class);
    }

    public function coughEvents()
    {
        return $this->hasMany(CoughEvent::class);
    }

    public function inhalerLogs()
    {
        return $this->hasMany(InhalerLog::class);
    }
}
