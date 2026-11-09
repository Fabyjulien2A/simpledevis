<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicInvoiceStatus extends Model
{
    protected $fillable = [
        'electronic_invoice_id',
        'iopole_status_id',
        'status',
        'payload',
        'received_at',
    ];

    protected $casts = [
        'electronic_invoice_id' => 'integer',
        'provider_event_id' => 'integer',
        'payload' => 'array',
        'received_at' => 'datetime',
    ];
}
