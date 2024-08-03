<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction;

interface RegisterInterface
{
    public static function getRaisedSegmentRegister(): int;
    public static function find(int $register): RegisterType;
    public static function map(): array;
    public static function addressBy(RegisterType $type): int;
}
