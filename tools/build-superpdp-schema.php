<?php

// Purely local, reproducible derivation. No application bootstrap, dependency or HTTP request.
// Run: php tools/build-superpdp-schema.php [--check]
$version = '1.39.0.beta';
$sourceHash = 'e071e043eee9d26ea667eb577d75de25fc9eb4641d0dd2c4516f2d0f874230e9';
$directory = __DIR__.'/../resources/schemas/superpdp/'.$version;
$raw = file_get_contents($directory.'/openapi.json');
if ($raw === false || hash('sha256', $raw) !== $sourceHash) {
    throw new RuntimeException('The pinned official OpenAPI source hash does not match.');
}
$document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
if ($document['info']['version'] !== $version || $document['openapi'] !== '3.0.4') {
    throw new RuntimeException('Unexpected OpenAPI version.');
}

$definitions = [];
$pending = ['en_invoice'];
$convert = function (mixed $node) use (&$convert, &$pending): mixed {
    if (! is_array($node)) {
        return $node;
    }
    // These OpenAPI-specific features require an explicit conversion policy if introduced upstream.
    foreach (['nullable', 'discriminator', 'readOnly', 'writeOnly', 'exclusiveMinimum', 'exclusiveMaximum'] as $keyword) {
        if (array_key_exists($keyword, $node)) {
            throw new RuntimeException('Unsupported OpenAPI keyword: '.$keyword);
        }
    }
    if (isset($node['$ref'])) {
        if (! preg_match('~^#/components/schemas/([A-Za-z0-9_]+)$~D', $node['$ref'], $matches)) {
            throw new RuntimeException('Only local component references are supported.');
        }
        $pending[] = $matches[1];
        $node['$ref'] = '#/definitions/'.$matches[1];
    }
    foreach ($node as $key => $value) {
        $node[$key] = $convert($value);
    }
    // Local strictness: upstream does not prohibit unknown properties.
    if (($node['type'] ?? null) === 'object') {
        if (isset($node['additionalProperties'])) {
            throw new RuntimeException('Review the upstream additionalProperties policy.');
        }
        $node['additionalProperties'] = false;
    }
    // OpenAPI's custom decimal format has no standard JSON Schema lexical validator.
    // Plain signed decimal strings, without exponent, spaces, comma or trailing newline.
    // No precision or numeric range is invented here; those remain business rules.
    if (($node['format'] ?? null) === 'decimal') {
        if (($node['type'] ?? null) !== 'string' || isset($node['pattern'])) {
            throw new RuntimeException('Review the upstream decimal definition.');
        }
        $node['pattern'] = '^-?[0-9]+(?:\.[0-9]+)?(?![\s\S])';
    }

    return $node;
};
while ($pending !== []) {
    $name = array_pop($pending);
    if (array_key_exists($name, $definitions)) {
        continue;
    }
    if (! isset($document['components']['schemas'][$name])) {
        throw new RuntimeException('Missing component: '.$name);
    }
    $definitions[$name] = $convert($document['components']['schemas'][$name]);
}
ksort($definitions);
$schema = [
    '$schema' => 'http://json-schema.org/draft-07/schema#',
    '$id' => 'urn:simpledevis:superpdp:'.$version.':en-invoice:strict-v1',
    'title' => 'SUPER PDP en_invoice — local structural contract',
    '$comment' => 'Derived locally from official OpenAPI. Not EN 16931 semantic validation or fiscal certification.',
    'x-source' => [
        'url' => 'https://api.superpdp.tech/openapi/superpdp.json',
        'version' => $version,
        'openapi' => $document['openapi'],
        'retrieved_on' => '2026-11-06',
        'sha256' => $sourceHash,
        'generator' => 'tools/build-superpdp-schema.php',
        'local_policy' => 'strict-v1: local refs, unknown properties forbidden, plain decimal strings',
    ],
    '$ref' => '#/definitions/en_invoice',
    'definitions' => $definitions,
];
$json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
$target = $directory.'/en-invoice.schema.json';
if (in_array('--check', $argv, true)) {
    if (! is_file($target) || file_get_contents($target) !== $json) {
        fwrite(STDERR, "Derived schema is not up to date.\n");
        exit(1);
    }
} elseif (file_put_contents($target, $json) === false) {
    throw new RuntimeException('Cannot write the derived schema.');
}
echo 'SUPER PDP '.$version.': '.count($definitions)." local definitions verified.\n";
