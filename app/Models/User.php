<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Modèle utilisateur.
 *
 * Un utilisateur représente ici un artisan inscrit sur SimpleDevis.
 * Il possède une entreprise, des clients, des devis et des factures.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * Les attributs assignables en masse.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * Les attributs cachés pour la sérialisation.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Les conversions de types automatiques.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Un utilisateur possède une seule fiche entreprise.
     */
    public function company(): HasOne
    {
        return $this->hasOne(CompanySetting::class);
    }

    /**
     * Un utilisateur possède plusieurs clients.
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * Un utilisateur possède plusieurs devis.
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * Un utilisateur possède plusieurs factures.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}