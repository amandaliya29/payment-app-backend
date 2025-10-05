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
     * Get the user that owns this Credit/UPI account.
     *
     * @return BelongsTo<\App\Models\User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
