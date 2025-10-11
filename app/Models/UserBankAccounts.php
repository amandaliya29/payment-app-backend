<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class UserBankAccounts extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'bank_id',
        'account_holder_name',
        'account_number',
        'ifsc_code',
        'pin_code',
    ];

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
     * Encrypt account number before saving to database.
     *
     * @param  string  $value
     * @return void
     */
    public function setAccountNumberAttribute($value): void
    {
        $this->attributes['account_number'] = Crypt::encryptString($value);
    }

    /**
     * Decrypt account number when retrieving from database.
     *
     * @param  string|null  $value
     * @return string|null
     */
    public function getAccountNumberAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    /**
     * Get the bank associated with the user bank account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function bank()
    {
        return $this->belongsTo(Bank::class, 'bank_id', 'id');
    }

    /**
     * Get the bank associated with the user bank account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function bankCreditUpi()
    {
        return $this->belongsTo(UserBankCreditUpi::class, 'id', 'bank_account_id');
    }
}
