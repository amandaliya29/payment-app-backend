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
        'ifsc_detail_id',
        'pin_code',
        'pin_code_length',
    ];

    /**
     * The attributes that should be hidden for arrays or JSON serialization.
     *
     * @var array
     */
    protected $hidden = [
        'amount',
        'pin_code'
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
     * Get the user that owns this model.
     *
     * Defines an inverse one-to-many (belongsTo) relationship
     * between this model and the User model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
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

    /**
     * Get the account type in capitalized format.
     * 
     * This accessor ensures that each word in the account type is capitalized
     * and underscores are replaced with spaces for display purposes.
     *
     * @param string $value The stored account type value.
     * @return string
     */
    public function getAccountTypeAttribute($value)
    {
        // Split by underscore, capitalize each word, then join with space
        $words = explode('_', strtolower($value));
        $words = array_map('ucfirst', $words);
        return implode(' ', $words); // e.g., "fixed_deposit" -> "Fixed Deposit"
    }

    /**
     * Get the IFSC detail associated with the user's bank account.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function ifscDetail()
    {
        return $this->belongsTo(IfscDetail::class, 'ifsc_detail_id');
    }
}
