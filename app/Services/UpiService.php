<?php

namespace App\Services;

use App\Models\UserBankAccounts;
use App\Models\UserBankCreditUpi;

class UpiService
{
    protected $handles = ['@oksbi', '@okaxis', '@okicici', '@okhdfcbank', '@okyesbank'];

    /**
     * Generate a random unique UPI ID for given user
     */
    public function generate(string $accountHolderName): string
    {
        $baseUpi = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $accountHolderName));
        if (empty($baseUpi)) {
            $baseUpi = 'user';
        }

        do {
            $handle = $this->handles[array_rand($this->handles)];
            $randomDigits = rand(1, 9999);
            $upiCandidate = $baseUpi . $randomDigits . $handle;
        } while (
            UserBankAccounts::where('upi_id', $upiCandidate)->exists() ||
            UserBankCreditUpi::where('upi_id', $upiCandidate)->exists()
        );

        return $upiCandidate;
    }
}
