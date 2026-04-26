<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use App\Notifications\CustomResetPasswordNotification;

class User extends Authenticatable
{
    use HasFactory, Notifiable, Billable;

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
     * Conversion automatique des types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * L'utilisateur possède une fiche entreprise.
     */
    public function company(): HasOne
    {
        return $this->hasOne(Company::class);
    }

    public function sendPasswordResetNotification($token): void
{
    $this->notify(new CustomResetPasswordNotification($token));
}

    /**
     * L'utilisateur possède plusieurs clients.
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * L'utilisateur possède plusieurs devis.
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * L'utilisateur possède plusieurs factures.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Vérifie si l'utilisateur a un abonnement actif.
     */
    public function isSubscribed(): bool
    {
        return $this->subscribed('default');
    }

    /**
     * Libellé du plan affichable dans l'interface.
     */
    public function planLabel(): string
    {
        return $this->isSubscribed() ? 'Plan Pro' : 'Plan Gratuit';
    }

    /**
     * Limite gratuite de devis.
     */
    public function freeQuotesLimit(): int
    {
        return 10;
    }

    /**
     * Nombre de devis restants sur le plan gratuit.
     */
    public function remainingFreeQuotes(): int
    {
        if ($this->isSubscribed()) {
            return 999999;
        }

        return max(0, $this->freeQuotesLimit() - $this->quotes()->count());
    }

    /**
     * Limite gratuite de clients.
     */
    public function freeClientsLimit(): int
    {
        return 5;
    }

    /**
     * Nombre de clients restants sur le plan gratuit.
     */
    public function remainingFreeClients(): int
    {
        if ($this->isSubscribed()) {
            return 999999;
        }

        return max(0, $this->freeClientsLimit() - $this->clients()->count());
    }

    /**
     * Limite gratuite de factures.
     */
    public function freeInvoicesLimit(): int
    {
        return 10;
    }

    /**
     * Nombre de factures restantes sur le plan gratuit.
     */
    public function remainingFreeInvoices(): int
    {
        if ($this->isSubscribed()) {
            return 999999;
        }

        return max(0, $this->freeInvoicesLimit() - $this->invoices()->count());
    }
}