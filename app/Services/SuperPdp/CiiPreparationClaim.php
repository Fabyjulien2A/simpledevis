<?php

namespace App\Services\SuperPdp;

use JsonSerializable;
use RuntimeException;

/** Process-local capability. Never serialize the completion secret or restore it after a crash. */
final readonly class CiiPreparationClaim implements JsonSerializable
{
    private function __construct(public string $id, private string $secret, private array $binding) {}

    public static function issue(array $binding): self
    {
        return new self(CiiPreparationJournal::key($binding), bin2hex(random_bytes(32)), $binding);
    }

    public function binding(): array
    {
        return $this->binding;
    }

    public function hash(): string
    {
        return hash('sha256', $this->secret);
    }

    public function assertHash(string $expected): void
    {
        if (! hash_equals($expected, $this->hash())) {
            throw new RuntimeException('Réservation CII détenue par une autre opération.');
        }
    }

    public function jsonSerialize(): array
    {
        return ['id' => $this->id];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Une capacité de préparation CII ne peut pas être persistée.');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('Une capacité de préparation CII ne peut pas être restaurée.');
    }
}
