<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Util;

interface UnsignedIntegerInterface
{
    public function toInt(): int;

    public function toHex(): string;

    public function add(self|int $other): self;

    public function sub(self|int $other): self;

    public function mul(self|int $other): self;

    public function div(self|int $other): self;

    public function mod(self|int $other): self;

    public function and(self|int $other): self;

    public function or(self|int $other): self;

    public function xor(self|int $other): self;

    public function not(): self;

    public function shl(int $bits): self;

    public function shr(int $bits): self;

    public function eq(self|int $other): bool;

    public function lt(self|int $other): bool;

    public function lte(self|int $other): bool;

    public function gt(self|int $other): bool;

    public function gte(self|int $other): bool;

    public function isZero(): bool;

    public function isNegativeSigned(): bool;
}
