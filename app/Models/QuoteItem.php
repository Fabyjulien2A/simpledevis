<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle ligne de devis.
 *
 * Chaque ligne correspond à une prestation ou un produit
 * lié à un devis.
 */
class QuoteItem extends Model
{
    /**
     * Les attributs assignables en masse.
     *
     * @var list<string>
     */
    protected $fillable = [
        'quote_id',
        'description',
        'quantity',
        'unit_price_ht',
        'tva_rate',
        'line_total_ht',
    ];

    /**
     * Conversions automatiques pour les montants.
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
     * Une ligne appartient à un devis.
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}