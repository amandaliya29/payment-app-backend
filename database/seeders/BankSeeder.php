<?php

namespace Database\Seeders;

use App\Models\Bank;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $banks = [
            [
                'name' => 'State Bank of India', 
                'slogan' => "India's largest public sector bank", 
                'logo' => '/images/banks/sbi.png'
            ],
            [
                'name' => 'HDFC Bank', 
                'slogan' => "India's leading private sector bank", 
                'logo' => '/images/banks/hdfc.png'
            ],
            [
                'name' => 'ICICI Bank', 
                'slogan' => 'Digital banking solutions', 
                'logo' => '/images/banks/icici.png'
            ],
            [
                'name' => 'Axis Bank', 
                'slogan' => 'Progressive banking solutions', 
                'logo' => '/images/banks/axis.png'
            ],
            [
                'name' => 'Punjab National Bank', 
                'slogan' => 'Trusted banking since 1894', 
                'logo' => '/images/banks/pnb.png'
            ],
        ];

        foreach ($banks as $bankData) {
            $bank = new Bank();
            $bank->name   = $bankData['name'];
            $bank->slogan = $bankData['slogan'];
            $bank->logo   = $bankData['logo'];
            $bank->save();
        }
    }
}
