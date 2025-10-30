<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class UserBankCreditUpi
 *
 * Represents a user's Credit/UPI account details.
 *
 * @package App\Models
 *
 * @property int $id
 * @property int $user_id
 * @property string $upi_id
 * @property float|null $credit_limit
 * @property float|null $used_credit
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\User $user
 */
class UserBankCreditUpi extends Model
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

    /**
     * Get the Bank associated with this UserBankCreditUpi through UserBankAccounts.
     *
     * This defines a "hasOneThrough" relationship:
     * - The final model is `Bank`.
     * - The intermediate model is `UserBankAccounts`.
     * - Local key on this model (`UserBankCreditUpi`) is `bank_account_id`.
     * - Foreign key on intermediate model (`UserBankAccounts`) is `bank_id`.
     * - Local key on intermediate model is `id`.
     * - Foreign key on final model (`Bank`) is `id`.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOneThrough
     */
    public function bank()
    {
        // Access the bank through bankAccount
        return $this->hasOneThrough(
            Bank::class,          // Final model
            UserBankAccounts::class,   // Intermediate model
            'id',                 // Foreign key on BankAccount (local key in this model is bank_account_id)
            'id',                 // Foreign key on Bank (bank_id in Bank table)
            'bank_account_id',    // Local key on UserBankCreditUpi
            'bank_id'             // Local key on BankAccount
        );
    }
}
