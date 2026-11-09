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
        'invoice_id' => 'integer',
        'provider_company_id' => 'integer',
        'provider_invoice_id' => 'integer',
        'business_event_id' => 'integer',
        'event_cursor' => 'integer',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function statuses()
    {
        return $this->hasMany(ElectronicInvoiceStatus::class);
    }

    public function attempts()
    {
        return $this->hasMany(ElectronicInvoiceAttempt::class);
    }
}
