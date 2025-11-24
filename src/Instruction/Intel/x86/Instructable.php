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
        return ($runtime->memoryAccessor()->fetch($segment)->asByte() << 4) & 0xFFFFF;
    }

    protected function segmentOffsetAddress(RuntimeInterface $runtime, RegisterType $segment, int $offset): int
    {
        return ($this->segmentBase($runtime, $segment) + ($offset & 0xFFFF)) & 0xFFFFF;
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
}
