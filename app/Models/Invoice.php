<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modèle facture.
 *
 * Une facture appartient à un utilisateur et à un client.
 * Elle peut provenir d'un devis.
 */
class Invoice extends Model
{
    public const FRENCH_BUSINESS_PROCESS_TYPES = ['B1', 'S1', 'M1'];

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
        'document_status',
        'french_business_process_type',
        'emission_profile',
        'calculation_version',
        'subtotal_ht',
        'total_tva',
        'total_ttc',
        'notes',

        // Facturation électronique / préparation réforme
        'is_electronic',
        'electronic_format',
        'pdp_id',
        'pdp_reference',
        'pdp_status',
        'xml_generated_at',
        'sent_to_pdp_at',
    ];

    /**
     * Conversion automatique des types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'emission_context' => 'array',
        'date' => 'date',
        'due_date' => 'date',
        'subtotal_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'party_snapshot' => 'array',

        // Facturation électronique
        'is_electronic' => 'boolean',
        'xml_generated_at' => 'datetime',
        'sent_to_pdp_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice) {
            if (! $invoice->isDirty(['emission_context', 'emission_profile'])) {
                return;
            }
            $stored = $invoice->newQuery()->whereKey($invoice->getKey())->first(['document_status', 'emission_context']);
            if ($stored && ($stored->isIssued()
                || ($invoice->isDirty('emission_context') && $stored->getRawOriginal('emission_context') !== null))) {
                throw ValidationException::withMessages(['emission_profile' => 'Les données d’émission figées ne peuvent pas être modifiées ou ajoutées après finalisation.']);
            }
        });

        static::updating(function (Invoice $invoice) {
            if (! $invoice->isDirty('party_snapshot')) {
                return;
            }

            $stored = $invoice->newQuery()->whereKey($invoice->getKey())
                ->first(['document_status', 'party_snapshot']);
            if ($stored && ($stored->getRawOriginal('party_snapshot') !== null || $stored->isIssued())) {
                throw ValidationException::withMessages([
                    'party_snapshot' => 'L’instantané enregistré ne peut pas être remplacé et une facture définitive ne peut pas être historisée après coup.',
                ]);
            }
        });

        static::updating(function (Invoice $invoice) {
            if (! $invoice->isDirty('french_business_process_type')) {
                return;
            }

            // Relire aussi le statut : une instance de brouillon peut être périmée.
            $stored = static::query()->whereKey($invoice->getKey())
                ->first(['document_status', 'french_business_process_type']);
            if ($stored?->isIssued()
                && $invoice->french_business_process_type !== $stored->french_business_process_type) {
                throw ValidationException::withMessages([
                    'french_business_process_type' => 'Le cadre de facturation d’une facture définitive ne peut pas être modifié.',
                ]);
            }
        });
    }

    public function electronicInvoices(): HasMany
    {
        return $this->hasMany(ElectronicInvoice::class);
    }

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
     * Une facture peut posséder plusieurs avoirs.
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
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
        // Total des avoirs définitifs associés à cette facture
        $totalCredits = (float) $this->creditNotes()
            ->where('status', 'issued')
            ->sum('total_ttc');

        // Montant net de la facture après déduction des avoirs
        $netTotal = max(
            0,
            (float) $this->total_ttc - $totalCredits
        );

        // Reste à payer après déduction des paiements
        return max(
            0,
            round($netTotal - $this->amount_paid_calculated, 2)
        );
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
     * Libellé lisible du statut e-facturation.
     */
    public function getPdpStatusLabelAttribute(): string
    {
        return match ($this->pdp_status) {
            'draft' => 'Brouillon',
            'generated' => 'XML généré',
            'ready' => 'Prête à envoyer',
            'sent' => 'Envoyée',
            'accepted' => 'Acceptée',
            'rejected' => 'Rejetée',
            default => 'Non préparée',
        };
    }

    /**
     * Indique si un XML a déjà été généré.
     */
    public function getHasGeneratedXmlAttribute(): bool
    {
        return ! empty($this->xml_generated_at);
    }

    /**
     * Marque la facture comme préparée en XML.
     */
    public function markXmlGenerated(): void
    {
        $this->update([
            'is_electronic' => true,
            'electronic_format' => 'xml',
            'pdp_status' => 'generated',
            'xml_generated_at' => now(),
        ]);
    }

    /**
     * Indique si la facture est un brouillon.
     */
    public function isDraft(): bool
    {
        return $this->document_status === 'draft';
    }

    /**
     * Indique si la facture est définitive.
     */
    public function isIssued(): bool
    {
        return $this->document_status === 'issued';
    }

    /**
     * Réserve le prochain numéro de facture pour un utilisateur.
     *
     * IMPORTANT : cette méthode doit être appelée à l'intérieur
     * d'une transaction DB::transaction().
     */
    public static function generateInvoiceNumber(int $userId): string
    {
        $year = now()->year;

        // Verrouille la ligne utilisateur pour sérialiser les attributions,
        // y compris lorsque le compteur de l'année n'existe pas encore.
        DB::table('users')
            ->where('id', $userId)
            ->lockForUpdate()
            ->first();

        $counter = DB::table('invoice_counters')
            ->where('user_id', $userId)
            ->where('year', $year)
            ->first();

        $nextNumber = $counter
            ? $counter->last_number + 1
            : 1;

        if ($counter) {
            DB::table('invoice_counters')
                ->where('id', $counter->id)
                ->update([
                    'last_number' => $nextNumber,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('invoice_counters')->insert([
                'user_id' => $userId,
                'year' => $year,
                'last_number' => $nextNumber,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return sprintf('FAC-%d-%04d', $year, $nextNumber);
    }
}
