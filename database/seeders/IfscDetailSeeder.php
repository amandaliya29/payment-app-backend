<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Models\IfscDetail;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class IfscDetailSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $banks = Bank::pluck('id', 'name');

        $ifscDetails = [
            [
                'bank_id' => $banks['State Bank of India'] ?? null,
                'ifsc_code' => 'SBIN0001234',
                'branch_name' => 'Surat Main Branch',
                'branch_address' => 'Ring Road',
                'city' => 'Surat',
                'state' => 'Gujarat',
            ],
            [
                'bank_id' => $banks['State Bank of India'] ?? null,
                'ifsc_code' => 'SBIN0005678',
                'branch_name' => 'Ahmedabad Branch',
                'branch_address' => 'Ashram Road',
                'city' => 'Ahmedabad',
                'state' => 'Gujarat',
            ],
            [
                'bank_id' => $banks['HDFC Bank'] ?? null,
                'ifsc_code' => 'HDFC0000456',
                'branch_name' => 'Surat Main Branch',
                'branch_address' => 'Ghod Dod Road',
                'city' => 'Surat',
                'state' => 'Gujarat',
            ],
            [
                'bank_id' => $banks['HDFC Bank'] ?? null,
                'ifsc_code' => 'HDFC0000789',
                'branch_name' => 'Mumbai Central Branch',
                'branch_address' => 'Fort Area',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
            ],
            [
                'bank_id' => $banks['ICICI Bank'] ?? null,
                'ifsc_code' => 'ICIC0000234',
                'branch_name' => 'Surat Ring Road Branch',
                'branch_address' => 'Ring Road',
                'city' => 'Surat',
                'state' => 'Gujarat',
            ],
            [
                'bank_id' => $banks['Axis Bank'] ?? null,
                'ifsc_code' => 'UTIB0000345',
                'branch_name' => 'Surat Adajan Branch',
                'branch_address' => 'Adajan Main Road',
                'city' => 'Surat',
                'state' => 'Gujarat',
            ],
            [
                'bank_id' => $banks['Punjab National Bank'] ?? null,
                'ifsc_code' => 'PUNB0000567',
                'branch_name' => 'Surat Textile Market Branch',
                'branch_address' => 'Ring Road',
                'city' => 'Surat',
                'state' => 'Gujarat',
            ],
        ];

        foreach ($ifscDetails as $detail) {
            if ($detail['bank_id']) {
                IfscDetail::updateOrCreate(
                    ['ifsc_code' => $detail['ifsc_code']],
                    $detail
                );
            }
        }
    }
}
