<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle facture.
 *
 * Une facture appartient à un utilisateur et à un client.
 * Elle peut provenir d'un devis.
 */
class Invoice extends Model
{
    /**
     * Les attributs assignables en masse.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'amount_paid',
        'client_id',
        'quote_id',
        'invoice_number',
        'date',
        'due_date',
        'status',
        'subtotal_ht',
        'total_tva',
        'total_ttc',
        'notes',
        'is_electronic',
        'pdp_id',
        'pdp_status',
    ];

    /**
     * Conversion automatique des types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'subtotal_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
        'amount_paid' => 'decimal:2',
    ];

    /**
     * Une facture appartient à un utilisateur.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Une facture appartient à un client.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Une facture peut provenir d'un devis.
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * Une facture contient plusieurs lignes.
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Une facture peut avoir plusieurs paiements.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Indique si la facture est en retard.
     */
    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date
            && $this->status !== 'payee'
            && now()->greaterThan($this->due_date);
    }

    /**
     * Total payé calculé à partir de l'historique des paiements.
     */
    public function getAmountPaidCalculatedAttribute(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    /**
     * Montant restant à payer.
     */
    public function getRemainingAmountAttribute(): float
    {
        return max(0, (float) $this->total_ttc - $this->amount_paid_calculated);
    }

    /**
     * Libellé lisible du statut de paiement.
     */
    public function getPaymentStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'non_payee' => 'Non payée',
            'partiellement_payee' => 'Partiellement payée',
            'payee' => 'Payée',
            default => ucfirst($this->status),
        };
    }

    /**
     * Génère un numéro de facture du type FAC-2026-0001.
     */
    public static function generateInvoiceNumber(): string
    {
        $year = now()->year;

        $lastInvoice = self::whereYear('created_at', $year)
            ->whereNotNull('invoice_number')
            ->orderByDesc('id')
            ->first();

        if (
            $lastInvoice &&
            preg_match('/FAC-' . $year . '-(\d{4})/', $lastInvoice->invoice_number, $matches)
        ) {
            $lastNumber = (int) $matches[1];
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return 'FAC-' . $year . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
