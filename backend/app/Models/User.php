<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasPushSubscriptions;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    public function inhalerLogs()
    {
        return $this->hasMany(InhalerLog::class);
    }

    public function hardwareConfig()
    {
        return $this->hasOne(HardwareConfig::class);
    }

    /**
     * Cough events from every device this account owns.
     */
    public function coughEvents()
    {
        return $this->hasManyThrough(CoughEvent::class, Device::class);
    }
}
