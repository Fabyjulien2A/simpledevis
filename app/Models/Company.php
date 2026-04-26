<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    'vat_number', // ✅ garder celui-là
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
}