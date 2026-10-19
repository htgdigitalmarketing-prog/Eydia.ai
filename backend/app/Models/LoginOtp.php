<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A short-lived, hashed one-time code for mobile-OTP login. Not tenant-scoped:
 * login happens before we know the tenant, and the phone is matched to a user.
 */
class LoginOtp extends Model
{
    protected $fillable = ['phone', 'code_hash', 'attempts', 'expires_at', 'consumed_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
