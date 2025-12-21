<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use Notifiable, HasApiTokens;

    protected $fillable = [
        'email',
        'username',
        'password',
        'role',
        'phone',
        'profile_picture',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'user_id');
    }

    // users ||--o{ events : "organizes"
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'organizer_id');
    }

    // users ||--o{ notifications : "receives"
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'user_id');
    }
}