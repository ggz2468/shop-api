<?php

namespace App\Models;

use App\Enums\Member\Gender;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Member extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'national_id_number',
        'email',
        'phone',
        'password',
    ];

    protected $hidden = [
        'id',
        'national_id_number',
        'email',
        'email_verified_at',
        'phone',
        'phone_verified_at',
        'password',
        'birth_date',
        'address',
        'remember_token',
        'deleted_at',
        'active_national_id_number',
        'active_email',
        'active_phone',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'password' => 'hashed',
        'birth_date' => 'date',
        'gender' => Gender::class,
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function shipmentStoreMapRequests(): HasMany
    {
        return $this->hasMany(ShipmentStoreMapRequest::class);
    }

    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    public function routeNotificationForVonage(object $notification): ?string
    {
        return $this->phone;
    }
}
