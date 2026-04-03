<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle client.
 *
 * Représente un client de l'artisan.
 * Un client peut avoir plusieurs devis et plusieurs factures.
 */
class Client extends Model
{
    /**
     * Les attributs assignables en masse.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'company_name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'postal_code',
        'city',
        'notes',
    ];

    /**
     * Un client appartient à un utilisateur.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Un client peut avoir plusieurs devis.
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * Un client peut avoir plusieurs factures.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Retourne le nom complet du client.
     *
     * Pratique pour l'affichage dans les vues.
     */
    public function getFullNameAttribute(): string
    {
        $fullName = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));

        return $fullName !== '' ? $fullName : ($this->company_name ?? 'Client sans nom');
    }
}