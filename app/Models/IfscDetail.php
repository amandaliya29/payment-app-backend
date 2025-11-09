<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IfscDetail extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'bank_id',
        'ifsc_code',
        'branch_name',
        'branch_address',
        'city',
        'state',
    ];

    /**
     * Get the bank associated with this IFSC detail.
     */
    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * Get all user bank accounts linked to this IFSC detail.
     */
    public function userBankAccounts()
    {
        return $this->hasMany(UserBankAccounts::class, 'ifsc_detail_id');
    }
}
