<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectronicInvoiceEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_invoice_id',
        'superpdp_event_id',
        'event_type',
        'status',
        'event_date',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'superpdp_event_id' => 'integer',
            'event_date' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }
}