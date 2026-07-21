<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Modèle entreprise.
 *
 * Contient les informations de l'entreprise de l'artisan :
 * nom, SIRET, TVA, coordonnées, logo, etc.
 */
class Company extends Model
{
    /**
     * Les attributs assignables en masse.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'company_name',
        'siret',
        'vat_number',
        'tva_number',
        'address',
        'postal_code',
        'city',
        'phone',
        'email',
        'logo',
        'legal_status',
        'iban',
        'bic',
        'payment_terms',
        'quote_validity',
    ];

    /**
     * Une entreprise appartient à un utilisateur.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Les factures fournisseurs reçues par l'entreprise.
     */
    public function supplierInvoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    /**
     * Connexion de l'entreprise à SUPER PDP.
     */
    public function superPdpConnection(): HasOne
    {
        return $this->hasOne(SuperPdpConnection::class);
    }
}