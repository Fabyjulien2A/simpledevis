<?php

return [
    // HTTP lifecycle can only exercise an explicitly injected test transport in testing.
    // This switch NEVER enables the SDK or a real sandbox deposit.
    'http_simulation_enabled' => false,
    // Separate, explicit operator switch. Sandbox runtime and isolated SQL scope
    // are mandatory; this can never enable the transport in production.
    'http_sandbox_enabled' => env('SUPERPDP_HTTP_SANDBOX_ENABLED', false) === true,
    // Filled only from the scoped private web grant at boot (or explicit CLI approval).
    // The HTTP opt-in alone never grants permission to send an invoice.
    'sandbox_enabled' => false,
    'sandbox_client_id' => null,
    // Trusted operator approval, scoped to owner/invoice/archive/company/hash,
    // expiring within 15 minutes. HTTP stores its UUID binding during reservation.
    'authorization' => null,
];
