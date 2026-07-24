<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\SupplierInvoicePayment;

class SupplierInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'superpdp_invoice_id',
        'superpdp_company_id',
        'direction',

        'invoice_number',
        'type_code',
        'invoice_date',
        'due_date',

        'supplier_name',
        'supplier_siren',
        'supplier_vat_number',
        'supplier_email',
        'supplier_address',
        'supplier_postal_code',
        'supplier_city',

        'currency',

        'total_ht',
        'total_vat',
        'total_ttc',
        'amount_due',

        'status',

        'pdf_path',
        'xml_path',

        'received_at',
        'payload',

        'payment_status',
        'paid_amount',
        'remaining_amount',
        'paid_at',
        'payment_method',
        'payment_reference',
        'payment_notes',

    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'received_at' => 'datetime',

            'superpdp_invoice_id' => 'integer',
            'superpdp_company_id' => 'integer',

            'total_ht' => 'decimal:2',
            'total_vat' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'amount_due' => 'decimal:2',

            'payload' => 'array',

            'paid_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    /**
     * Entreprise SimpleDevis propriétaire de la facture reçue.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Lignes de la facture fournisseur.
     */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierInvoiceItem::class)
            ->orderBy('line_number');
    }

    /**
     * Événements de cycle de vie de la facture.
     */
    public function events(): HasMany
    {
        return $this->hasMany(ElectronicInvoiceEvent::class)
            ->orderBy('event_date');
    }

    /**
     * Traduction du statut de la facture.
     */

    public function statusLabel(): string
    {
        return match ($this->status) {
            'received' => 'Reçue',
            'processing' => 'En traitement',
            'accepted' => 'Acceptée',
            'rejected' => 'Rejetée',
            default => ucfirst($this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'received' => 'bg-blue-100 text-blue-700',
            'processing' => 'bg-yellow-100 text-yellow-700',
            'accepted' => 'bg-green-100 text-green-700',
            'rejected' => 'bg-red-100 text-red-700',
            default => 'bg-gray-100 text-gray-700',
        };
    }

    /**
 * Libellé du statut de paiement.
 */
public function paymentStatusLabel(): string
{
    return match ($this->payment_status) {
        'paid' => 'Payée',
        'partial' => 'Partiellement payée',
        default => 'À payer',
    };
}

/**
 * Couleur du badge.
 */
public function paymentStatusColor(): string
{
    return match ($this->payment_status) {
        'paid' => 'bg-green-100 text-green-700',
        'partial' => 'bg-yellow-100 text-yellow-700',
        default => 'bg-red-100 text-red-700',
    };
}

/**
 * Paiements enregistrés pour cette facture.
 */
public function payments(): HasMany
{
    return $this->hasMany(SupplierInvoicePayment::class)
        ->latest('paid_at');
}

/**
 * Recalcule les montants et le statut de paiement.
 */
public function refreshPaymentTotals(): void
{
    $totalPaid = (float) $this->payments()
        ->sum('amount');

    $remainingAmount = max(
        0,
        (float) $this->total_ttc - $totalPaid
    );

    $paymentStatus = match (true) {
        $totalPaid <= 0 => 'unpaid',
        $remainingAmount > 0 => 'partial',
        default => 'paid',
    };

    $this->update([
        'paid_amount' => $totalPaid,
        'remaining_amount' => $remainingAmount,
        'payment_status' => $paymentStatus,
    ]);
}


}
