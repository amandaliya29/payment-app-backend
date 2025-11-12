<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNpciCreditUpi extends Model
{
    /**
     * The attributes that should be hidden for arrays or JSON serialization.
     *
     * @var array
     */
    protected $hidden = [
        'pin_code',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'pin_code' => 'hashed',
    ];

    /**
     * Get the user that owns this Credit/UPI account.
     *
     * @return BelongsTo<\App\Models\User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
