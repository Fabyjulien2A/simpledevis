<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle ligne de facture.
 *
 * Chaque ligne représente une prestation ou un produit
 * inclus dans une facture.
 */
class InvoiceItem extends Model
{
    /**
     * Les attributs assignables en masse.
     *
     * @var list<string>
     */
    protected $fillable = [
        'invoice_id',
        'description',
        'quantity',
        'unit_price_ht',
        'tva_rate',
        'line_total_ht',
    ];

    /**
     * Conversion automatique des nombres.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price_ht' => 'decimal:2',
        'tva_rate' => 'decimal:2',
        'line_total_ht' => 'decimal:2',
    ];

    /**
     * Une ligne appartient à une facture.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}