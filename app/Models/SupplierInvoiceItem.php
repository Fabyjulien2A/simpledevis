<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_invoice_id',
        'line_number',
        'description',
        'quantity',
        'unit_code',
        'unit_price_ht',
        'line_total_ht',
        'vat_rate',
        'vat_amount',
        'line_total_ttc',
        'discount_amount',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price_ht' => 'decimal:6',
            'line_total_ht' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'line_total_ttc' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'payload' => 'array',
        ];
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}