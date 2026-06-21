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
        'payload' => 'array',
        'received_at' => 'datetime',
    ];
}
