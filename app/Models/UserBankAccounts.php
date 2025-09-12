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
        'aadhaar_number',
        'pan_number',
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
     * Encrypt Aadhaar number before saving to database.
     *
     * @param  string  $value
     * @return void
     */
    public function setAadhaarNumberAttribute($value): void
    {
        $this->attributes['aadhaar_number'] = Crypt::encryptString($value);
    }

    /**
     * Decrypt Aadhaar number when retrieving from database.
     *
     * @param  string|null  $value
     * @return string|null
     */
    public function getAadhaarNumberAttribute($value): ?string
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
