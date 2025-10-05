<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class Transaction
 *
 * @package App\Models
 *
 * @property int $id
 * @property int $user_id
 * @property string $type      Transaction type (bank, credit_upi)
 * @property string $mode      Mode (debit, credit)
 * @property float $amount
 * @property string $status    Transaction status (completed, pending, failed, etc.)
 * @property string|null $description
 * @property string|null $from_account_id
 * @property string|null $to_account_id
 * @property string|null $from_upi_id
 * @property string|null $to_upi_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \App\Models\User $user
 * @property-read \App\Models\UserBankAccounts|null $senderBank
 * @property-read \App\Models\UserBankAccounts|null $receiverBank
 * @property-read \App\Models\UserBankCreditUpi|null $senderCreditUpi
 * @property-read \App\Models\UserBankCreditUpi|null $senderUpi
 */
class Transaction extends Model
{
    /**
     * Get the user who initiated the transaction.
     *
     * @return BelongsTo<\App\Models\User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the sender's bank account details.
     *
     * @return BelongsTo<\App\Models\UserBankAccounts, self>
     */
    public function senderBank(): BelongsTo
    {
        return $this->belongsTo(UserBankAccounts::class, 'from_account_id')->with('user');
    }

    /**
     * Get the receiver's bank account details.
     *
     * @return BelongsTo<\App\Models\UserBankAccounts, self>
     */
    public function receiverBank(): BelongsTo
    {
        return $this->belongsTo(UserBankAccounts::class, 'to_account_id')->with('user');
    }

    /**
     * Get the sender's Credit/UPI details.
     *
     * @return BelongsTo<\App\Models\UserBankCreditUpi, self>
     */
    public function senderCreditUpi(): BelongsTo
    {
        return $this->belongsTo(UserBankCreditUpi::class, 'from_upi_id', 'upi_id')->with('user');
    }

    /**
     * Get the sender's UPI details (mapped from from_upi_id).
     *
     * @return BelongsTo<\App\Models\UserBankCreditUpi, self>
     */
    public function senderUpi(): BelongsTo
    {
        return $this->belongsTo(UserBankCreditUpi::class, 'from_upi_id', 'upi_id')->with('user');
    }
}
