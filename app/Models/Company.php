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
        'vat_number',
        'address',
        'postal_code',
        'city',
        'phone',
        'email',
        'logo',
    ];

    /**
     * Une entreprise appartient à un utilisateur.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}