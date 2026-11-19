<?php

$closed = [
    // Separate from emission. No real preparation calls are authorized by default.
    'live_enabled' => false,
    // Independent opt-in for the existing HTTP POST. Sandbox only; never emission.
    'web_enabled' => false,
    'sandbox_client_id' => null,
    'authorization' => null,
];

// Temporary server approval for the isolated sandbox invoice 83 only.
// Expiration closes both switches on subsequent requests; operation guards also
// recheck this same deadline immediately before any remote preparation.
$authorization = [
    'approved_by' => 'explicit_user_approval_sandbox_invoice_83',
    'approved_at' => 1794849900,
    'expires_at' => 1794850262,
    'user_id' => 3,
    'invoice_id' => 83,
    'source_sha256' => '420f01595b44981a49913f1ffc75797caa12d8eec9d8e4a3b512f73ef62d09a0',
    'verification_reference' => 'invoice-party-checks/v1/3/83/f4afdb44b7f2fce174cc1b3c37291dc32145695e3849f58cdc85a564d6b5baac.json',
    'provider_company_id' => 18172,
    'environment' => 'sandbox',
    'oauth_client_id' => '019f61e0-875b-79fc-a2da-1a636dfb1c1f',
    'api_url' => 'https://api.superpdp.tech/v1.beta',
];

if (env('APP_ENV') !== 'sandbox'
    || env('DB_CONNECTION') !== 'superpdp_sandbox'
    || env('DB_DATABASE') !== 'fabyjulien_simpledevis_test'
    || env('DB_USERNAME') !== 'fabyjulien_simpledevis_test'
    || env('SUPERPDP_ENVIRONMENT') !== $authorization['environment']
    || env('SUPERPDP_API_URL') !== $authorization['api_url']
    || env('SUPERPDP_CLIENT_ID') !== $authorization['oauth_client_id']
    || time() < $authorization['approved_at']
    || time() >= $authorization['expires_at']) {
    return $closed;
}

return [
    'live_enabled' => true,
    'web_enabled' => true,
    'sandbox_client_id' => $authorization['oauth_client_id'],
    'authorization' => $authorization,
];
