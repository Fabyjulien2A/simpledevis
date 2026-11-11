<?php

return [
    // Deliberately not environment switches. No authorization has been granted.
    'sandbox_enabled' => false,
    'sandbox_client_id' => null,
    // Trusted operator approval, scoped to owner/invoice/archive/company/hash,
    // expiring within 15 minutes. POST additionally requires its attempt UUID.
    'authorization' => null,
];
