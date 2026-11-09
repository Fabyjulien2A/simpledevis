<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicInvoiceAttempt extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['claim_token'];

    protected $casts = ['history' => 'array', 'reconciliation' => 'array', 'transport_started_at' => 'datetime', 'finished_at' => 'datetime',
        'number' => 'integer', 'electronic_invoice_id' => 'integer', 'http_status' => 'integer', 'provider_invoice_id' => 'integer'];
}
