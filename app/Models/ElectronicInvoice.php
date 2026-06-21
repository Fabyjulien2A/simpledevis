<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ElectronicInvoice extends Model
{
    protected $fillable = [
        'invoice_id',
        'iopole_invoice_id',
        'status',
        'request_payload',
        'response_payload',
        'last_error',
        'sent_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function statuses()
    {
        return $this->hasMany(ElectronicInvoiceStatus::class);
    }
}
