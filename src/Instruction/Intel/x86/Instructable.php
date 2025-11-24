<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

trait Instructable
{
    public function __construct(protected InstructionListInterface $instructionList)
    {

    }

    protected function decode8BitRegister(int $register): array
    {
        return [
            match ($register & 0b11) {
                0b00 => RegisterType::EAX,
                0b01 => RegisterType::ECX,
                0b10 => RegisterType::EDX,
                0b11 => RegisterType::EBX,
            },
            ($register & 0b100) === 0b100, // true when targeting the high byte (AH/CH/DH/BH)
        ];
    }

    protected function read8BitRegister(RuntimeInterface $runtime, int $register): int
    {
        [$registerType, $isHigh] = $this->decode8BitRegister($register);
        $fetch = $runtime->memoryAccessor()->fetch($registerType);

        return $isHigh
            ? $fetch->asHighBit()    // AH/CH/DH/BH
            : $fetch->asLowBit();  // AL/CL/DL/BL
    }

    protected function write8BitRegister(RuntimeInterface $runtime, int $register, int $value, bool $updateFlags = true): void
    {
        [$registerType, $isHigh] = $this->decode8BitRegister($register);
        $memoryAccessor = $runtime->memoryAccessor();

        if (!$updateFlags) {
            $memoryAccessor->enableUpdateFlags(false);
        }

        if ($isHigh) {
            $memoryAccessor->writeToHighBit($registerType, $value);   // AH/CH/DH/BH
        } else {
            $memoryAccessor->writeToLowBit($registerType, $value);  // AL/CL/DL/BL
        }
    }

    protected function segmentBase(RuntimeInterface $runtime, RegisterType $segment): int
    {
        $selector = $runtime->memoryAccessor()->fetch($segment)->asByte();

        if ($runtime->runtimeOption()->context()->isProtectedMode()) {
            $gdtr = $runtime->runtimeOption()->context()->gdtr();
            $base = $gdtr['base'] ?? 0;
            $limit = $gdtr['limit'] ?? 0;
            $index = ($selector >> 3) & 0x1FFF;
            $offset = $base + ($index * 8);
            if ($offset + 7 > $base + $limit) {
                return 0;
            }

            $b0 = $this->readMemory8($runtime, $offset + 2);
            $b1 = $this->readMemory8($runtime, $offset + 3);
            $b2 = $this->readMemory8($runtime, $offset + 4);
            $b7 = $this->readMemory8($runtime, $offset + 7);

            $segBase = ($b0) | ($b1 << 8) | ($b2 << 16) | ($b7 << 24);
            return $segBase & 0xFFFFFFFF;
        }

        return ($selector << 4) & 0xFFFFF;
    }

    protected function segmentOffsetAddress(RuntimeInterface $runtime, RegisterType $segment, int $offset): int
    {
        return ($this->segmentBase($runtime, $segment) + ($offset & 0xFFFF)) & 0xFFFFF;
    }

    protected function readRegisterBySize(RuntimeInterface $runtime, int $register, int $size): int
    {
        return match ($size) {
            8 => $this->read8BitRegister($runtime, $register),
            16 => $runtime->memoryAccessor()->fetch($register)->asByte(),
            32 => $runtime->memoryAccessor()->fetch($register)->asBytesBySize(32),
            default => $runtime->memoryAccessor()->fetch($register)->asBytesBySize($size),
        };
    }

    protected function writeRegisterBySize(RuntimeInterface $runtime, int $register, int $value, int $size): void
    {
        match ($size) {
            8 => $this->write8BitRegister($runtime, $register, $value),
            16 => $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit($register, $value),
            32 => $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($register, $value, 32),
            default => $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($register, $value, $size),
        };
    }

    protected function defaultSegmentFor16(ModRegRMInterface $modRegRM): RegisterType
    {
        $mode = ModType::from($modRegRM->mode());
        $rm = $modRegRM->registerOrMemoryAddress();

        $usesSS = in_array($rm, [0b010, 0b011], true)
            || ($rm === 0b110 && $mode !== ModType::NO_DISPLACEMENT_OR_16BITS_DISPLACEMENT);

        return $usesSS ? RegisterType::SS : RegisterType::DS;
    }

    protected function effectiveAddressInfo(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): array
    {
        if ($runtime->runtimeOption()->context()->addressSize() === 32) {
            return $this->effectiveAddressAndSegment32($runtime, $reader, $modRegRM);
        }

        return [
            $this->effectiveAddress16($runtime, $reader, $modRegRM),
            $this->defaultSegmentFor16($modRegRM),
        ];
    }

    protected function rmLinearAddress(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, RegisterType|null $segmentOverride = null): int
    {
        [$offset, $defaultSegment] = $this->effectiveAddressInfo($runtime, $reader, $modRegRM);
        $segment = $segmentOverride ?? $runtime->segmentOverride() ?? $defaultSegment;

        return $this->segmentOffsetAddress($runtime, $segment, $offset);
    }

    /**
     * Resolve 16-bit effective address for the given ModR/M.
     */
    protected function effectiveAddress16(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): int
    {
        $mode = ModType::from($modRegRM->mode());
        $rm = $modRegRM->registerOrMemoryAddress();
        $disp = 0;

        if ($mode === ModType::SIGNED_8BITS_DISPLACEMENT) {
            $disp = $reader->streamReader()->signedByte();
        } elseif ($mode === ModType::SIGNED_16BITS_DISPLACEMENT) {
            $disp = $reader->signedShort();
        }

        $val = static function (RuntimeInterface $runtime, RegisterType $reg): int {
            return $runtime->memoryAccessor()->fetch($reg)->asByte();
        };

        $address = match ($rm) {
            0b000 => $val($runtime, RegisterType::EBX) + $val($runtime, RegisterType::ESI),
            0b001 => $val($runtime, RegisterType::EBX) + $val($runtime, RegisterType::EDI),
            0b010 => $val($runtime, RegisterType::EBP) + $val($runtime, RegisterType::ESI),
            0b011 => $val($runtime, RegisterType::EBP) + $val($runtime, RegisterType::EDI),
            0b100 => $val($runtime, RegisterType::ESI),
            0b101 => $val($runtime, RegisterType::EDI),
            0b110 => $mode === ModType::NO_DISPLACEMENT_OR_16BITS_DISPLACEMENT
                ? $reader->short() // direct address
                : $val($runtime, RegisterType::EBP),
            0b111 => $val($runtime, RegisterType::EBX),
        };

        return ($address + $disp) & 0xFFFF;
    }

    /**
     * 32-bit effective address with default segment.
     * Returns [offset, segment]
     */
    protected function effectiveAddressAndSegment32(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): array
    {
        $mode = ModType::from($modRegRM->mode());
        $rm = $modRegRM->registerOrMemoryAddress();
        $disp = 0;
        $baseVal = 0;
        $indexVal = 0;
        $scale = 0;
        $defaultSegment = RegisterType::DS;

        $regVal = static function (RuntimeInterface $runtime, int $code): int {
            $map = [
                RegisterType::EAX,
                RegisterType::ECX,
                RegisterType::EDX,
                RegisterType::EBX,
                RegisterType::ESP,
                RegisterType::EBP,
                RegisterType::ESI,
                RegisterType::EDI,
            ];
            return $runtime->memoryAccessor()->fetch($map[$code])->asByte();
        };

        if ($mode === ModType::SIGNED_8BITS_DISPLACEMENT) {
            $disp = $reader->streamReader()->signedByte();
        } elseif ($mode === ModType::SIGNED_16BITS_DISPLACEMENT) {
            $disp = $reader->signedDword();
        }

        if ($rm === 0b100) {
            $sib = $reader->byteAsSIB();
            $scale = 1 << $sib->scale();
            $indexVal = $sib->index() === 0b100 ? 0 : $regVal($runtime, $sib->index());

            if ($sib->base() === 0b101 && $mode === ModType::NO_DISPLACEMENT_OR_16BITS_DISPLACEMENT) {
                $disp = $reader->signedDword();
            } else {
                $baseVal = $regVal($runtime, $sib->base());
                $defaultSegment = in_array($sib->base(), [0b100, 0b101], true) ? RegisterType::SS : RegisterType::DS;
            }
        } else {
            if ($mode === ModType::NO_DISPLACEMENT_OR_16BITS_DISPLACEMENT && $rm === 0b101) {
                $disp = $reader->signedDword();
            } else {
                $baseVal = $regVal($runtime, $rm);
                $defaultSegment = in_array($rm, [0b100, 0b101], true) ? RegisterType::SS : RegisterType::DS;
            }
        }

        $offset = ($baseVal + $indexVal * $scale + $disp) & 0xFFFFF;

        return [$offset, $defaultSegment];
    }

    protected function readRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $size): int
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            return $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size);
        }

        $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        return match ($size) {
            8 => $this->readMemory8($runtime, $address),
            16 => $this->readMemory16($runtime, $address),
            32 => $this->readMemory32($runtime, $address),
            default => $this->readMemory16($runtime, $address) & ((1 << $size) - 1),
        };
    }

    protected function writeRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $value, int $size): void
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $value, $size);
            return;
        }

        $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $runtime->memoryAccessor()->allocate($address, safe: false);
        match ($size) {
            8 => $runtime->memoryAccessor()->writeBySize($address, $value, 8),
            16 => $runtime->memoryAccessor()->write16Bit($address, $value),
            32 => $runtime->memoryAccessor()->writeBySize($address, $value, 32),
            default => $runtime->memoryAccessor()->writeBySize($address, $value, $size),
        };
    }

    protected function readRm8(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): int
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            return $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress());
        }

        $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        return $this->readMemory8($runtime, $address);
    }

    protected function writeRm8(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $value): void
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $value);
            return;
        }

        $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $runtime->memoryAccessor()->allocate($address, safe: false);
        $runtime->memoryAccessor()->writeBySize($address, $value, 8);
    }

    protected function readRm16(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): int
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            return $runtime->memoryAccessor()->fetch($modRegRM->registerOrMemoryAddress())->asByte();
        }

        $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        return $this->readMemory16($runtime, $address);
    }

    protected function writeRm16(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, int $value): void
    {
        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            $runtime->memoryAccessor()->write16Bit($modRegRM->registerOrMemoryAddress(), $value);
            return;
        }

        $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $runtime->memoryAccessor()->allocate($address, safe: false);
        $runtime->memoryAccessor()->write16Bit($address, $value);
    }

    protected function readMemory8(RuntimeInterface $runtime, int $address): int
    {
        $value = $runtime->memoryAccessor()->tryToFetch($address)?->asHighBit();
        if ($value !== null) {
            return $value;
        }

        try {
            $origin = $runtime->addressMap()->getOrigin();
            if ($address < $origin) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        try {
            $proxy = $runtime->streamReader()->proxy();
            $currentOffset = $runtime->streamReader()->offset();
            $proxy->setOffset(
                $runtime->addressMap()->getDisk()->entrypointOffset() + ($address - $runtime->addressMap()->getOrigin())
            );
            $byte = $proxy->byte();
            $proxy->setOffset($currentOffset);
            return $byte;
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function readMemory16(RuntimeInterface $runtime, int $address): int
    {
        $value = $runtime->memoryAccessor()->tryToFetch($address)?->asByte();
        if ($value !== null) {
            return $value;
        }

        try {
            $origin = $runtime->addressMap()->getOrigin();
            if ($address < $origin) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        try {
            $proxy = $runtime->streamReader()->proxy();
            $currentOffset = $runtime->streamReader()->offset();
            $proxy->setOffset(
                $runtime->addressMap()->getDisk()->entrypointOffset() + ($address - $runtime->addressMap()->getOrigin())
            );
            $lo = $proxy->byte();
            $hi = $proxy->byte();
            $proxy->setOffset($currentOffset);
            return ($hi << 8) + $lo;
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function readMemory32(RuntimeInterface $runtime, int $address): int
    {
        $value = $runtime->memoryAccessor()->tryToFetch($address)?->asBytesBySize(32);
        if ($value !== null) {
            return $value;
        }

        try {
            $origin = $runtime->addressMap()->getOrigin();
            if ($address < $origin) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        try {
            $proxy = $runtime->streamReader()->proxy();
            $currentOffset = $runtime->streamReader()->offset();
            $proxy->setOffset(
                $runtime->addressMap()->getDisk()->entrypointOffset() + ($address - $runtime->addressMap()->getOrigin())
            );
            $b1 = $proxy->byte();
            $b2 = $proxy->byte();
            $b3 = $proxy->byte();
            $b4 = $proxy->byte();
            $proxy->setOffset($currentOffset);
            return ($b4 << 24) + ($b3 << 16) + ($b2 << 8) + $b1;
        } catch (\Throwable) {
            return 0;
        }
    }
}
