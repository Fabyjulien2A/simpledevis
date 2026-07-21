<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\SupplierInvoice;
use Illuminate\Database\Seeder;

class SupplierInvoiceSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::findOrFail(4);

        SupplierInvoice::where('company_id', 4)->delete();

        SupplierInvoice::create([
            'company_id' => $company->id,
            'invoice_number' => 'EDF-2026-001',
            'invoice_date' => '2026-07-01',
            'due_date' => '2026-07-31',
            'supplier_name' => 'EDF',
            'supplier_siren' => '552081317',
            'currency' => 'EUR',
            'total_ht' => 100,
            'total_vat' => 20,
            'total_ttc' => 120,
            'status' => 'received',
            'received_at' => now(),
        ]);

        SupplierInvoice::create([
            'company_id' => $company->id,
            'invoice_number' => 'ORANGE-2026-002',
            'invoice_date' => '2026-07-05',
            'due_date' => '2026-08-04',
            'supplier_name' => 'Orange',
            'supplier_siren' => '380129866',
            'currency' => 'EUR',
            'total_ht' => 70.75,
            'total_vat' => 14.15,
            'total_ttc' => 84.90,
            'status' => 'paid',
            'received_at' => now(),
        ]);

        SupplierInvoice::create([
            'company_id' => $company->id,
            'invoice_number' => 'AMAZON-2026-003',
            'invoice_date' => '2026-07-10',
            'due_date' => '2026-08-09',
            'supplier_name' => 'Amazon',
            'supplier_siren' => '487773327',
            'currency' => 'EUR',
            'total_ht' => 262.83,
            'total_vat' => 52.57,
            'total_ttc' => 315.40,
            'status' => 'processing',
            'received_at' => now(),
        ]);
    }
}