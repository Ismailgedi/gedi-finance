<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles, CanResetPassword;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function sendPasswordResetNotification($token): void
    {
        $url = rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/')
            .'/reset-password/' . urlencode($token)
            .'?email=' . urlencode($this->email);

        $this->notify(new ResetPasswordNotification($url));
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }
}
