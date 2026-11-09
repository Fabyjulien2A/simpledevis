<?php

namespace App\Services\SuperPdp\Emission;

use RuntimeException;

final class EmissionJson
{
    public static function object(string $body): array
    {
        if (strlen($body) > 1048576) {
            throw new RuntimeException('Réponse trop volumineuse.');
        }
        $object = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        if (! $object instanceof \stdClass) {
            throw new RuntimeException('Objet JSON attendu.');
        }
        preg_match_all('~"(?:[^"\\\\]|\\\\.)*"|[{}\[\]]~s', $body, $matches, PREG_OFFSET_CAPTURE);
        $stack = [];
        foreach ($matches[0] as [$token, $offset]) {
            if ($token === '{' || $token === '[') {
                $stack[] = $token === '{' ? [] : null;
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (preg_match('/\A\s*:/', substr($body, $offset + strlen($token)))) {
                $index = array_key_last($stack);
                $key = json_decode($token, true, 64, JSON_THROW_ON_ERROR);
                if (isset($stack[$index][$key])) {
                    throw new RuntimeException('Clé JSON ambiguë.');
                }
                $stack[$index][$key] = true;
            }
        }

        return json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    }
}
