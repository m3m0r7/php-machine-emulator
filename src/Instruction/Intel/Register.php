<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\RegisterNotFoundException;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\Instruction\RegisterType;

class Register implements RegisterInterface
{
    public static function getRaisedSegmentRegister(): int
    {
        return 0b1000;
    }

    public static function getRaisedDestinationRegister(): int
    {
        return 0b10000;
    }

    public static function find(int $register): RegisterType
    {
        foreach (self::map() as $name => $value) {
            if ($register === $value) {
                return constant(RegisterType::class . '::' . $name);
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

            // NOTE: In this project, directly writing to the file stream would overwrite the file itself,
            //       so we internally maintain registers that can be modified in memory.
            //       This allows efficient operations on the DI register.
            RegisterType::EDI_ON_MEMORY->name => 0b111 + self::getRaisedDestinationRegister(),

            RegisterType::ES->name => 0b0000 + self::getRaisedSegmentRegister(),
            RegisterType::CS->name => 0b0001 + self::getRaisedSegmentRegister(),
            RegisterType::SS->name => 0b0010 + self::getRaisedSegmentRegister(),
            RegisterType::DS->name => 0b0011 + self::getRaisedSegmentRegister(),
            RegisterType::FS->name => 0b0100 + self::getRaisedSegmentRegister(),
            RegisterType::GS->name => 0b0101 + self::getRaisedSegmentRegister(),
        ];
    }

    public static function addressBy(RegisterType $type): int
    {
        return static::map()[$type->name] ?? throw new RegisterNotFoundException('Register not found');
    }
}
