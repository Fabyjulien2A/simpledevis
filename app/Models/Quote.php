<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Modèle devis.
 *
 * Un devis appartient à un utilisateur et à un client.
 * Il contient plusieurs lignes et peut être converti en facture.
 */
class Quote extends Model
{
    /**
     * Les attributs assignables en masse.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'client_id',
        'quote_number',
        'date',
        'status',
        'subtotal_ht',
        'total_tva',
        'total_ttc',
        'notes',
    ];

    /**
     * Conversion automatique du champ date.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date',
        'subtotal_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    /**
     * Un devis appartient à un utilisateur.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Un devis appartient à un client.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Un devis contient plusieurs lignes.
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    /**
     * Un devis peut être converti en une facture.
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * Génère un numéro de devis du type DEV-2026-0001.
     */
    public static function generateQuoteNumber(): string
    {
        $year = now()->year;

        $lastQuote = self::whereYear('created_at', $year)
            ->whereNotNull('quote_number')
            ->orderByDesc('id')
            ->first();

        if (
            $lastQuote &&
            preg_match('/DEV-' . $year . '-(\d{4})/', $lastQuote->quote_number, $matches)
        ) {
            $lastNumber = (int) $matches[1];
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return 'DEV-' . $year . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }
}