<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\RegisterNotFoundException;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\Instruction\RegisterType;

class Register implements RegisterInterface
{
    public static function find(int $register): RegisterType
    {
        foreach (self::map() as $name => $value) {
            if ($register === $value) {
                return RegisterType::cases()[$name];
            }
        }

        throw new RegisterNotFoundException('Register not found');
    }

    public static function map(): array
    {
        return [
            RegisterType::EAX->name => 0b000,
            RegisterType::ECX->name => 0b001,
            RegisterType::EDX->name => 0b010,
            RegisterType::EBX->name => 0b011,
            RegisterType::ESP->name => 0b100,
            RegisterType::EBP->name => 0b101,
            RegisterType::ESI->name => 0b110,
            RegisterType::EDI->name => 0b111,
        ];
    }

    public static function addressBy(RegisterType $type): int
    {
        return static::map()[$type->name] ?? throw new RegisterNotFoundException('Register not found');
    }
}
