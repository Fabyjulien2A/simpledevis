<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuperPdpConnection extends Model
{
    use HasFactory;

    protected $table = 'superpdp_connections';

    protected $fillable = [
        'company_id',
        'environment',
        'oauth_client_id',
        'api_url',
        'superpdp_company_id',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'directory_identifier',
        'reception_enabled',
        'last_sync_at',
        'last_invoice_id',
        'last_event_id',
        'status',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            /*
             * Les tokens seront automatiquement chiffrés avant stockage
             * et déchiffrés lorsque Laravel les lit.
             */
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',

            'access_token_expires_at' => 'datetime',
            'last_sync_at' => 'datetime',

            'reception_enabled' => 'boolean',

            'superpdp_company_id' => 'integer',
            'last_invoice_id' => 'integer',
            'last_event_id' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assertOAuthContext(string $environment, string $clientId, string $apiUrl): void
    {
        if (! in_array($environment, ['sandbox', 'production'], true)
            || $this->environment !== $environment || $clientId === '' || $this->oauth_client_id !== $clientId
            || $apiUrl === '' || $this->api_url !== $apiUrl) {
            throw new \RuntimeException('Connexion OAuth non qualifiée pour cet environnement/client. Reconnectez votre entreprise.');
        }
    }

    public function tokenIsExpired(): bool
    {
        if (! $this->access_token_expires_at) {
            return true;
        }

        return $this->access_token_expires_at
            ->lte(now()->addMinutes(5));
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected'
            && ! empty($this->refresh_token);
    }
}
