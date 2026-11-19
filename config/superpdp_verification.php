<?php

return [
    // Activation séparée après autorisation d'un premier GET réel. Aucun déclencheur automatique.
    'live_reads_enabled' => false,
    // Interrupteur indépendant, sans activation des autres transports.
    'destination_live_reads_enabled' => false,
    // Choix local de sécurité, pas une durée imposée par SUPER PDP.
    'destination_validity_minutes' => 15,
    'publication_validity_minutes' => 15,
    // Démonstration sans réseau, uniquement en environnement local/testing.
    // null, match, absent, inactive, ambiguous, incompatible, api_error.
    'destination_demo' => env('SUPERPDP_DESTINATION_DEMO'),
];
