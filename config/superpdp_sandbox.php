<?php

return [
    // Independent opt-in: never reuse reception tokens or their persistence/refresh flow.
    'conversion_enabled' => env('SUPERPDP_SANDBOX_CONVERSION_ENABLED', false),
];
