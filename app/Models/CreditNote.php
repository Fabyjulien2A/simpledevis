<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNote extends Model
{
    protected $fillable = [
        'user_id',
        'invoice_id',
        'credit_note_number',
        'date',
        'status',
        'reason',
        'subtotal_ht',
        'total_tva',
        'total_ttc',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'subtotal_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    /**
     * L'artisan propriétaire de l'avoir.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * La facture d'origine.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Indique si l'avoir est définitif.
     */
    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }
}
