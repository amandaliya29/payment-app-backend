<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class UserBankAccounts extends Model
{
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
}
