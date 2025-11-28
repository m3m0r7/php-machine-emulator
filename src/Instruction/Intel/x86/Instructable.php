<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Ata;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Pit;
use PHPMachineEmulator\Instruction\Intel\x86\Cmos;
use PHPMachineEmulator\Instruction\Intel\x86\KeyboardController;
use PHPMachineEmulator\Instruction\Intel\x86\PicState;
use PHPMachineEmulator\Instruction\Intel\x86\ApicState;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Exception\FaultException;
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

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $descriptor = $this->readSegmentDescriptor($runtime, $selector);
            if ($descriptor === null || !$descriptor['present']) {
                // In protected mode with null/invalid selector:
                // - Selector 0 is the null selector; allow with base 0 for flat model compatibility
                // - Other invalid selectors should fault, but for boot compatibility
                //   we log and use base 0 to allow early setup code to work
                if ($selector !== 0) {
                    $runtime->option()->logger()->debug(sprintf(
                        'Segment selector 0x%04X not present in GDT, using base 0',
                        $selector
                    ));
                }
                return 0;
            }

            if ($segment === RegisterType::CS) {
                $runtime->context()->cpu()->setCpl($descriptor['dpl']);
                $runtime->context()->cpu()->setUserMode($descriptor['dpl'] === 3);
            }

            return $descriptor['base'];
        }

        return ($selector << 4) & 0xFFFFF;
    }

    protected function segmentOffsetAddress(RuntimeInterface $runtime, RegisterType $segment, int $offset): int
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $offsetMask = $addressSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $linearMask = $this->linearMask($runtime);

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $selector = $runtime->memoryAccessor()->fetch($segment)->asByte();
            $descriptor = $this->readSegmentDescriptor($runtime, $selector);
            if ($descriptor !== null && $descriptor['present']) {
                $effOffset = $offset & $offsetMask;
                if ($effOffset > $descriptor['limit']) {
                    throw new FaultException(0x0D, $selector, sprintf('Segment limit exceeded for selector 0x%04X', $selector));
                }
                return ($descriptor['base'] + $effOffset) & $linearMask;
            }
        }

        return ($this->segmentBase($runtime, $segment) + ($offset & $offsetMask)) & $linearMask;
    }

    protected function linearMask(RuntimeInterface $runtime): int
    {
        return $runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
    }

    protected function linearCodeAddress(RuntimeInterface $runtime, int $selector, int $offset, int $opSize): int
    {
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $linearMask = $this->linearMask($runtime);

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $descriptor = $this->readSegmentDescriptor($runtime, $selector);
            if ($descriptor === null || !$descriptor['present']) {
                throw new FaultException(0x0B, $selector, sprintf('Code segment not present for selector 0x%04X', $selector));
            }
            if (($descriptor['system'] ?? false)) {
                throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not a code segment', $selector));
            }
            if (!($descriptor['executable'] ?? false)) {
                throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not executable', $selector));
            }
            if (!($descriptor['system'] ?? false)) {
                $dpl = $descriptor['dpl'];
                $rpl = $selector & 0x3;
                $cpl = $runtime->context()->cpu()->cpl();
                $conforming = ($descriptor['type'] & 0x4) !== 0;
                if ($conforming) {
                    if ($cpl < $dpl) {
                        throw new FaultException(0x0D, $selector, sprintf('Conforming code selector 0x%04X requires CPL >= DPL', $selector));
                    }
                } else {
                    if (max($cpl, $rpl) > $dpl) {
                        throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X privilege check failed', $selector));
                    }
                }
            }
            if (($offset & $mask) > $descriptor['limit']) {
                throw new FaultException(0x0D, $selector, sprintf('EIP exceeds segment limit for selector 0x%04X', $selector));
            }
            return ($descriptor['base'] + ($offset & $mask)) & $linearMask;
        }

        return ((($selector << 4) + ($offset & $mask)) & $linearMask);
    }

    protected function codeOffsetFromLinear(RuntimeInterface $runtime, int $selector, int $linear, int $opSize): int
    {
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $linearMask = $this->linearMask($runtime);

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $descriptor = $this->readSegmentDescriptor($runtime, $selector);
            if ($descriptor === null || !$descriptor['present']) {
                throw new FaultException(0x0B, $selector, sprintf('Code segment not present for selector 0x%04X', $selector));
            }
            if (($descriptor['system'] ?? false)) {
                throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not a code segment', $selector));
            }
            $offset = ($linear - $descriptor['base']) & 0xFFFFFFFF;
            if ($offset > $descriptor['limit']) {
                throw new FaultException(0x0D, $selector, sprintf('Return offset exceeds segment limit for selector 0x%04X', $selector));
            }
            return $offset & $mask;
        }

        return ($linear - (($selector << 4) & $linearMask)) & $mask;
    }

    protected function readIndex(RuntimeInterface $runtime, RegisterType $register): int
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        return $runtime->memoryAccessor()->fetch($register)->asBytesBySize($addressSize);
    }

    protected function writeIndex(RuntimeInterface $runtime, RegisterType $register, int $value): void
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $mask = $addressSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($register, $value & $mask, $addressSize);
    }

    protected function stepForElement(RuntimeInterface $runtime, int $bytes): int
    {
        return $runtime->memoryAccessor()->shouldDirectionFlag() ? -$bytes : $bytes;
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
        if ($runtime->context()->cpu()->addressSize() === 32) {
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
            return $runtime->memoryAccessor()->fetch($map[$code])->asBytesBySize(32);
        };

        // x86 encoding order: ModR/M → SIB (if present) → Displacement
        // SIB byte must be read BEFORE displacement when rm=4
        if ($rm === 0b100) {
            $sib = $reader->byteAsSIB();
            $scale = 1 << $sib->scale();
            $indexVal = $sib->index() === 0b100 ? 0 : $regVal($runtime, $sib->index());

            // Now read displacement after SIB
            if ($mode === ModType::SIGNED_8BITS_DISPLACEMENT) {
                $disp = $reader->streamReader()->signedByte();
            } elseif ($mode === ModType::SIGNED_16BITS_DISPLACEMENT) {
                $disp = $reader->signedDword();
            }

            if ($sib->base() === 0b101 && $mode === ModType::NO_DISPLACEMENT_OR_16BITS_DISPLACEMENT) {
                $disp = $reader->signedDword();
            } else {
                $baseVal = $regVal($runtime, $sib->base());
                $defaultSegment = in_array($sib->base(), [0b100, 0b101], true) ? RegisterType::SS : RegisterType::DS;
            }
        } else {
            // No SIB, read displacement directly
            if ($mode === ModType::SIGNED_8BITS_DISPLACEMENT) {
                $disp = $reader->streamReader()->signedByte();
            } elseif ($mode === ModType::SIGNED_16BITS_DISPLACEMENT) {
                $disp = $reader->signedDword();
            }

            if ($mode === ModType::NO_DISPLACEMENT_OR_16BITS_DISPLACEMENT && $rm === 0b101) {
                $disp = $reader->signedDword();
            } else {
                $baseVal = $regVal($runtime, $rm);
                $defaultSegment = in_array($rm, [0b100, 0b101], true) ? RegisterType::SS : RegisterType::DS;
            }
        }

        $mask = $runtime->context()->cpu()->isProtectedMode() ? 0xFFFFFFFF : 0xFFFFF;
        $offset = ($baseVal + $indexVal * $scale + $disp) & $mask;

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

        $linearAddress = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        match ($size) {
            8 => $this->writeMemory8($runtime, $linearAddress, $value),
            16 => $this->writeMemory16($runtime, $linearAddress, $value),
            32 => $this->writeMemory32($runtime, $linearAddress, $value),
            default => $this->writeMemory32($runtime, $linearAddress, $value),
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

        $linearAddress = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $this->writeMemory8($runtime, $linearAddress, $value);
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

        $linearAddress = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $this->writeMemory16($runtime, $linearAddress, $value);
    }

    protected function readMemory8(RuntimeInterface $runtime, int $address): int
    {
        $physical = $this->translateLinear($runtime, $address);
        return $this->readPhysical8($runtime, $physical);
    }

    protected function readMemory16(RuntimeInterface $runtime, int $address): int
    {
        $physical = $this->translateLinear($runtime, $address);
        return $this->readPhysical16($runtime, $physical);
    }

    protected function readMemory32(RuntimeInterface $runtime, int $address): int
    {
        $physical = $this->translateLinear($runtime, $address);
        return $this->readPhysical32($runtime, $physical);
    }

    protected function writeMemory8(RuntimeInterface $runtime, int $address, int $value): void
    {
        $physical = $this->translateLinear($runtime, $address, true);
        if ($this->writeMmio($runtime, $physical, $value & 0xFF, 8)) {
            return;
        }
        $runtime->memoryAccessor()->allocate($physical, safe: false);
        // Store raw byte value directly (no encoding needed for single byte)
        $runtime->memoryAccessor()->writeRawByte($physical, $value & 0xFF);
    }

    protected function writeMemory16(RuntimeInterface $runtime, int $address, int $value): void
    {
        $physical = $this->translateLinear($runtime, $address, true);
        if ($this->writeMmio($runtime, $physical, $value & 0xFFFF, 16)) {
            return;
        }
        // Write two bytes in little-endian order
        $this->writeMemory8($runtime, $address, $value & 0xFF);
        $this->writeMemory8($runtime, $address + 1, ($value >> 8) & 0xFF);
    }

    protected function writeMemory32(RuntimeInterface $runtime, int $address, int $value): void
    {
        $physical = $this->translateLinear($runtime, $address, true);
        if ($this->writeMmio($runtime, $physical, $value & 0xFFFFFFFF, 32)) {
            return;
        }
        // Write four bytes in little-endian order
        $this->writeMemory8($runtime, $address, $value & 0xFF);
        $this->writeMemory8($runtime, $address + 1, ($value >> 8) & 0xFF);
        $this->writeMemory8($runtime, $address + 2, ($value >> 16) & 0xFF);
        $this->writeMemory8($runtime, $address + 3, ($value >> 24) & 0xFF);
    }

    protected function readSegmentDescriptor(RuntimeInterface $runtime, int $selector): ?array
    {
        $ti = ($selector >> 2) & 0x1;
        if ($ti === 1) {
            $ldtr = $runtime->context()->cpu()->ldtr();
            $base = $ldtr['base'] ?? 0;
            $limit = $ldtr['limit'] ?? 0;
            // If LDTR not loaded (selector 0), treat as invalid.
            if (($ldtr['selector'] ?? 0) === 0) {
                return null;
            }
        } else {
            $gdtr = $runtime->context()->cpu()->gdtr();
            $base = $gdtr['base'] ?? 0;
            $limit = $gdtr['limit'] ?? 0;
        }

        $index = ($selector >> 3) & 0x1FFF;
        $offset = $base + ($index * 8);

        if ($offset + 7 > $base + $limit) {
            return null;
        }

        $limitLow = $this->readMemory8($runtime, $offset) | ($this->readMemory8($runtime, $offset + 1) << 8);
        $baseLow = $this->readMemory8($runtime, $offset + 2) | ($this->readMemory8($runtime, $offset + 3) << 8);
        $baseMid = $this->readMemory8($runtime, $offset + 4);
        $access = $this->readMemory8($runtime, $offset + 5);
        $gran = $this->readMemory8($runtime, $offset + 6);
        $baseHigh = $this->readMemory8($runtime, $offset + 7);

        $limitHigh = $gran & 0x0F;
        $fullLimit = $limitLow | ($limitHigh << 16);
        if (($gran & 0x80) !== 0) {
            $fullLimit = ($fullLimit << 12) | 0xFFF;
        }

        $baseAddr = $baseLow | ($baseMid << 16) | ($baseHigh << 24);
        $present = ($access & 0x80) !== 0;
        $type = $access & 0x1F;
        $system = ($access & 0x10) === 0;
        $executable = ($access & 0x08) !== 0;
        $dpl = ($access >> 5) & 0x3;

        return [
            'base' => $baseAddr & 0xFFFFFFFF,
            'limit' => $fullLimit & 0xFFFFFFFF,
            'present' => $present,
            'type' => $type,
            'system' => $system,
            'executable' => $executable,
            'dpl' => $dpl,
            'default' => ($gran & 0x40) !== 0 ? 32 : 16,
        ];
    }

    protected function readCallGateDescriptor(RuntimeInterface $runtime, int $selector): ?array
    {
        $ti = ($selector >> 2) & 0x1;
        if ($ti === 1) {
            $ldtr = $runtime->context()->cpu()->ldtr();
            $base = $ldtr['base'] ?? 0;
            $limit = $ldtr['limit'] ?? 0;
            if (($ldtr['selector'] ?? 0) === 0) {
                return null;
            }
        } else {
            $gdtr = $runtime->context()->cpu()->gdtr();
            $base = $gdtr['base'] ?? 0;
            $limit = $gdtr['limit'] ?? 0;
        }

        $index = ($selector >> 3) & 0x1FFF;
        $offset = $base + ($index * 8);
        if ($offset + 7 > $base + $limit) {
            return null;
        }

        $offsetLow = $this->readMemory16($runtime, $offset);
        $targetSelector = $this->readMemory16($runtime, $offset + 2);
        $paramCount = $this->readMemory8($runtime, $offset + 4) & 0x1F;
        $access = $this->readMemory8($runtime, $offset + 5);
        $offsetHigh = $this->readMemory16($runtime, $offset + 6);

        $type = $access & 0x1F;
        $present = ($access & 0x80) !== 0;
        $dpl = ($access >> 5) & 0x3;
        $is32 = ($type & 0x8) !== 0;
        $targetOffset = ($offsetLow | ($offsetHigh << 16)) & 0xFFFFFFFF;

        $isTaskGate = $type === 0x5;
        if (!$isTaskGate && !in_array($type, [0x4, 0xC], true)) {
            return null;
        }

        return [
            'type' => $type,
            'present' => $present,
            'dpl' => $dpl,
            'offset' => $targetOffset,
            'selector' => $targetSelector,
            'wordCount' => $paramCount,
            'is32' => $is32,
            'gateSelector' => $selector & 0xFFFF,
            'isTaskGate' => $isTaskGate,
        ];
    }

    protected function callThroughGate(RuntimeInterface $runtime, array $gate, int $returnOffset, int $returnCs, int $opSize, bool $pushReturn = true, bool $copyParams = true): void
    {
        $cpl = $runtime->context()->cpu()->cpl();
        $rpl = $gate['gateSelector'] & 0x3;
        if (max($cpl, $rpl) > $gate['dpl']) {
            throw new FaultException(0x0D, $gate['gateSelector'], sprintf('Call gate 0x%04X privilege check failed', $gate['gateSelector']));
        }

        if (!$gate['present']) {
            throw new FaultException(0x0B, $gate['gateSelector'], sprintf('Call gate 0x%04X not present', $gate['gateSelector']));
        }

        if ($gate['isTaskGate'] ?? false) {
            $this->taskSwitch($runtime, $gate['selector'], true, $gate['gateSelector'], !$pushReturn);
            return;
        }

        $targetSelector = $gate['selector'];
        if (($targetSelector & 0xFFFC) === 0) {
            throw new FaultException(0x0D, $targetSelector, 'Null selector via call gate');
        }

        $targetDesc = $this->resolveCodeDescriptor($runtime, $targetSelector);
        $newCpl = $this->computeCplForTransfer($runtime, $targetSelector, $targetDesc);
        $privilegeChange = $newCpl < $cpl;

        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $oldSs = $ma->fetch(RegisterType::SS)->asByte();
        $oldEsp = $ma->fetch(RegisterType::ESP)->asBytesBySize($opSize);
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $paramSize = $gate['is32'] ? 4 : 2;
        $params = [];

        if ($copyParams && $privilegeChange && $gate['wordCount'] > 0) {
            for ($i = 0; $i < $gate['wordCount']; $i++) {
                $srcOffset = ($oldEsp + ($i * $paramSize)) & 0xFFFFFFFF;
                $srcLinear = $this->segmentOffsetAddress($runtime, RegisterType::SS, $srcOffset);
                $params[] = $paramSize === 4
                    ? $this->readMemory32($runtime, $srcLinear)
                    : $this->readMemory16($runtime, $srcLinear);
            }
        }

        if ($privilegeChange) {
            $tss = $runtime->context()->cpu()->taskRegister();
            $tssSelector = $tss['selector'] ?? 0;
            $tssBase = $tss['base'] ?? 0;
            $tssLimit = $tss['limit'] ?? 0;
            if ($tssSelector === 0) {
                throw new FaultException(0x0A, 0, 'Task register not loaded for call gate privilege change');
            }
            $espOffset = 4 + ($newCpl * 8);
            $ssOffset = 8 + ($newCpl * 8);
            if ($tssLimit < $ssOffset + 3) {
                throw new FaultException(0x0A, $tssSelector, sprintf('TSS too small for ring %d stack', $newCpl));
            }
            $newEsp = $this->readMemory32($runtime, $tssBase + $espOffset);
            $newSs = $this->readMemory16($runtime, $tssBase + $ssOffset);

            $ma->write16Bit(RegisterType::SS, $newSs & 0xFFFF);
            $ma->writeBySize(RegisterType::ESP, $newEsp & $mask, $opSize);
            $runtime->context()->cpu()->setCpl($newCpl);
            $runtime->context()->cpu()->setUserMode($newCpl === 3);

            // Copy parameters from old stack to new stack (deepest first) when requested.
            if ($copyParams) {
                for ($i = count($params) - 1; $i >= 0; $i--) {
                    $ma->push(RegisterType::ESP, $params[$i], $paramSize === 4 ? 32 : 16);
                }
            }

            // push old SS:ESP on new stack
            $ma->push(RegisterType::ESP, $oldSs, $opSize);
            $ma->push(RegisterType::ESP, $oldEsp, $opSize);
        }

        if ($pushReturn) {
            // push return CS:EIP on current (or switched) stack
            $ma->push(RegisterType::ESP, $returnCs, $opSize);
            $ma->push(RegisterType::ESP, $returnOffset, $opSize);
        }

        $targetOffset = $gate['offset'] & ($gate['is32'] ? 0xFFFFFFFF : 0xFFFF);
        $linearTarget = $this->linearCodeAddress($runtime, $targetSelector, $targetOffset, $opSize);
        $runtime->streamReader()->setOffset($linearTarget);
        $this->writeCodeSegment($runtime, $targetSelector, $newCpl, $targetDesc);
    }

    protected function taskSwitch(RuntimeInterface $runtime, int $tssSelector, bool $setBusy = true, ?int $gateSelector = null, bool $isJump = false): void
    {
        $oldTr = $runtime->context()->cpu()->taskRegister();
        $oldSelector = $oldTr['selector'] ?? 0;
        $oldBase = $oldTr['base'] ?? 0;
        $oldLimit = $oldTr['limit'] ?? 0;
        $oldTssDesc = null;
        if ($oldSelector !== 0) {
            $oldTssDesc = $this->readSegmentDescriptor($runtime, $oldSelector);
        }

        $newDesc = $this->readSegmentDescriptor($runtime, $tssSelector);
        if ($newDesc === null) {
            throw new FaultException(0x0D, $tssSelector, sprintf('Invalid TSS selector 0x%04X', $tssSelector));
        }
        if (!$newDesc['present']) {
            throw new FaultException(0x0B, $tssSelector, sprintf('TSS selector 0x%04X not present', $tssSelector));
        }
        $type = $newDesc['type'] ?? 0;
        $is32 = in_array($type, [0x9, 0xB], true);
        $validTypes = [0x9, 0xB]; // only 32-bit TSS supported here
        if (!in_array($type, $validTypes, true)) {
            throw new FaultException(0x0D, $tssSelector, sprintf('Selector 0x%04X is not a 32-bit TSS', $tssSelector));
        }
        if (($type === 0x3 || $type === 0xB)) {
            // busy TSS cannot be target except via IRET or JMP to same task
            if ($tssSelector !== $oldSelector) {
                throw new FaultException(0x0D, $tssSelector, sprintf('TSS selector 0x%04X is busy', $tssSelector));
            }
        }

        // Offsets for 32-bit TSS fields
        $tss32 = $this->tss32Offsets();

        // Save old state into current TSS if present (32-bit TSS layout).
        if ($oldSelector !== 0 && $oldTssDesc !== null && ($oldTssDesc['type'] ?? 0) === 0xB) {
            $oldCr3 = $runtime->memoryAccessor()->readControlRegister(3);
            $csSel = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte();
            $oldEip = $this->codeOffsetFromLinear($runtime, $csSel, $runtime->streamReader()->offset(), 32);
            $flagsVal = $this->packFlags($runtime);

            // TSS32 layout
            $this->writeMemory32($runtime, $oldBase + $tss32['cr3'], $oldCr3 & 0xFFFFFFFF);
            $this->writeMemory32($runtime, $oldBase + $tss32['eip'], $oldEip & 0xFFFFFFFF);
            $this->writeMemory32($runtime, $oldBase + $tss32['eflags'], $flagsVal);
            $this->writeMemory32($runtime, $oldBase + $tss32['eax'], $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize(32));
            $this->writeMemory32($runtime, $oldBase + $tss32['ecx'], $runtime->memoryAccessor()->fetch(RegisterType::ECX)->asBytesBySize(32));
            $this->writeMemory32($runtime, $oldBase + $tss32['edx'], $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asBytesBySize(32));
            $this->writeMemory32($runtime, $oldBase + $tss32['ebx'], $runtime->memoryAccessor()->fetch(RegisterType::EBX)->asBytesBySize(32));
            $this->writeMemory32($runtime, $oldBase + $tss32['esp'], $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(32));
            $this->writeMemory32($runtime, $oldBase + $tss32['ebp'], $runtime->memoryAccessor()->fetch(RegisterType::EBP)->asBytesBySize(32));
            $this->writeMemory32($runtime, $oldBase + $tss32['esi'], $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize(32));
            $this->writeMemory32($runtime, $oldBase + $tss32['edi'], $runtime->memoryAccessor()->fetch(RegisterType::EDI)->asBytesBySize(32));
            $this->writeMemory16($runtime, $oldBase + $tss32['es'], $runtime->memoryAccessor()->fetch(RegisterType::ES)->asByte());
            $this->writeMemory16($runtime, $oldBase + $tss32['cs'], $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte());
            $this->writeMemory16($runtime, $oldBase + $tss32['ss'], $runtime->memoryAccessor()->fetch(RegisterType::SS)->asByte());
            $this->writeMemory16($runtime, $oldBase + $tss32['ds'], $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte());
            $this->writeMemory16($runtime, $oldBase + $tss32['fs'], $runtime->memoryAccessor()->fetch(RegisterType::FS)->asByte());
            $this->writeMemory16($runtime, $oldBase + $tss32['gs'], $runtime->memoryAccessor()->fetch(RegisterType::GS)->asByte());
            $this->writeMemory16($runtime, $oldBase + $tss32['ldtr'], $runtime->context()->cpu()->ldtr()['selector'] ?? 0);
        }

        // Load new TSS state (basic parts).
        $newBase = $newDesc['base'];
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $runtime->memoryAccessor()->writeControlRegister(3, $this->readMemory32($runtime, $newBase + $tss32['cr3']));
        $newEip = $this->readMemory32($runtime, $newBase + $tss32['eip']);
        $newEflags = $this->readMemory32($runtime, $newBase + $tss32['eflags']);
        $ma->writeBySize(RegisterType::EAX, $this->readMemory32($runtime, $newBase + $tss32['eax']), 32);
        $ma->writeBySize(RegisterType::ECX, $this->readMemory32($runtime, $newBase + $tss32['ecx']), 32);
        $ma->writeBySize(RegisterType::EDX, $this->readMemory32($runtime, $newBase + $tss32['edx']), 32);
        $ma->writeBySize(RegisterType::EBX, $this->readMemory32($runtime, $newBase + $tss32['ebx']), 32);
        $ma->writeBySize(RegisterType::ESP, $this->readMemory32($runtime, $newBase + $tss32['esp']), 32);
        $ma->writeBySize(RegisterType::EBP, $this->readMemory32($runtime, $newBase + $tss32['ebp']), 32);
        $ma->writeBySize(RegisterType::ESI, $this->readMemory32($runtime, $newBase + $tss32['esi']), 32);
        $ma->writeBySize(RegisterType::EDI, $this->readMemory32($runtime, $newBase + $tss32['edi']), 32);
        $ma->write16Bit(RegisterType::ES, $this->readMemory16($runtime, $newBase + $tss32['es']));
        $ma->write16Bit(RegisterType::CS, $this->readMemory16($runtime, $newBase + $tss32['cs']));
        $ma->write16Bit(RegisterType::SS, $this->readMemory16($runtime, $newBase + $tss32['ss']));
        $ma->write16Bit(RegisterType::DS, $this->readMemory16($runtime, $newBase + $tss32['ds']));
        $ma->write16Bit(RegisterType::FS, $this->readMemory16($runtime, $newBase + $tss32['fs']));
        $ma->write16Bit(RegisterType::GS, $this->readMemory16($runtime, $newBase + $tss32['gs']));

        $runtime->context()->cpu()->setTaskRegister($tssSelector, $newBase, $newDesc['limit']);
        $runtime->context()->cpu()->setCpl($this->readMemory16($runtime, $newBase + $tss32['cs']) & 0x3);
        $runtime->context()->cpu()->setUserMode($runtime->context()->cpu()->cpl() === 3);

        if ($setBusy && ($type === 0x1 || $type === 0x9)) {
            // Mark new TSS busy
            $gdtr = $runtime->context()->cpu()->gdtr();
            $base = $gdtr['base'] ?? 0;
            $index = ($tssSelector >> 3) & 0x1FFF;
            $descAddr = $base + ($index * 8);
            $accessAddr = $descAddr + 5;
            $access = $this->readMemory8($runtime, $accessAddr) | 0x02;
            $phys = $this->translateLinear($runtime, $accessAddr, true);
            $runtime->memoryAccessor()->allocate($phys, safe: false);
            $runtime->memoryAccessor()->writeBySize($phys, $access & 0xFF, 8);
        }

        if ($oldTssDesc !== null && ($oldTssDesc['type'] ?? 0) === 0xB) {
            // Clear busy bit of old TSS if it was busy (for task gate switches).
            $gdtr = $runtime->context()->cpu()->gdtr();
            $base = $gdtr['base'] ?? 0;
            $index = ($oldSelector >> 3) & 0x1FFF;
            $descAddr = $base + ($index * 8);
            $accessAddr = $descAddr + 5;
            $access = $this->readMemory8($runtime, $accessAddr) & 0xFD;
            $phys = $this->translateLinear($runtime, $accessAddr, true);
            $runtime->memoryAccessor()->allocate($phys, safe: false);
            $runtime->memoryAccessor()->writeBySize($phys, $access & 0xFF, 8);
        }

        if ($gateSelector !== null) {
            // Save backlink
            $backlink = $oldSelector;
            $runtime->memoryAccessor()->write16Bit($newBase + $tss32['backlink'], $backlink & 0xFFFF);
        }

        if ($runtime->option()->shouldChangeOffset()) {
            $linearTarget = $this->linearCodeAddress($runtime, $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte(), $newEip, 32);
            $runtime->streamReader()->setOffset($linearTarget);
        }

        // EFLAGS loaded from TSS (only low 16 bits honored in 32-bit TSS).
        $this->applyFlags($runtime, $newEflags, 32);
    }

    protected function translateLinear(RuntimeInterface $runtime, int $linear, bool $isWrite = false): int
    {
        $mask = $this->linearMask($runtime);
        $linear &= $mask;

        if (!$runtime->context()->cpu()->isPagingEnabled()) {
            return $linear;
        }

        $user = $runtime->context()->cpu()->cpl() === 3;
        $cr4 = $runtime->memoryAccessor()->readControlRegister(4);
        $pse = ($cr4 & (1 << 4)) !== 0;
        $pae = ($cr4 & (1 << 5)) !== 0;

        if ($pae) {
            $cr3 = $runtime->memoryAccessor()->readControlRegister(3) & 0xFFFFF000;
            $pdpIndex = ($linear >> 30) & 0x3;
            $dirIndex = ($linear >> 21) & 0x1FF;
            $tableIndex = ($linear >> 12) & 0x1FF;
            $offset = $linear & 0xFFF;

            $pdpteAddr = ($cr3 + ($pdpIndex * 8)) & 0xFFFFFFFF;
            $pdpte = $this->readPhysical64($runtime, $pdpteAddr);
            if (($pdpte & 0x1) === 0) {
                $err = ($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0);
                throw new FaultException(0x0E, $err, 'PDPT entry not present');
            }
            if (($pdpte & (~0x7FF)) !== 0) {
                $err = 0x08 | ($runtime->memoryAccessor()->shouldInstructionFetch() ? 0x10 : 0);
                throw new FaultException(0x0E, $err, 'Reserved bit set in PDPT');
            }
            if ($user && (($pdpte & 0x4) === 0)) {
                $err = ($isWrite ? 0b10 : 0) | 0b100 | 0b1;
                throw new FaultException(0x0E, $err, 'PDPT entry not user accessible');
            }
            if ($isWrite && (($pdpte & 0x2) === 0)) {
                $err = 0b10 | ($user ? 0b100 : 0) | 0b1;
                throw new FaultException(0x0E, $err, 'PDPT entry not writable');
            }

            // Mark PDPT accessed.
            $this->writePhysical64($runtime, $pdpteAddr, $pdpte | (1 << 5));

            $pdeAddr = (($pdpte & 0xFFFFFF000) + ($dirIndex * 8)) & 0xFFFFFFFF;
            $pde = $this->readPhysical64($runtime, $pdeAddr);
            if (($pde & 0x1) === 0) {
                $err = ($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0);
                throw new FaultException(0x0E, $err, 'Page directory entry not present');
            }
            $isLarge = ($pde & (1 << 7)) !== 0;
            if (($pde & (~0x7FF)) !== 0) {
                $err = 0x08 | ($runtime->memoryAccessor()->shouldInstructionFetch() ? 0x10 : 0);
                throw new FaultException(0x0E, $err, 'Reserved bit set in PDE');
            }
            if ($user && (($pde & 0x4) === 0)) {
                $err = ($isWrite ? 0b10 : 0) | 0b100 | 0b1;
                throw new FaultException(0x0E, $err, 'Page directory entry not user accessible');
            }
            if ($isWrite && (($pde & 0x2) === 0)) {
                $err = 0b10 | ($user ? 0b100 : 0) | 0b1;
                throw new FaultException(0x0E, $err, 'Page directory entry not writable');
            }

            if ($isLarge) {
                $pde |= 0x20;
                if ($isWrite) {
                    $pde |= 0x40;
                }
                $this->writePhysical64($runtime, $pdeAddr, $pde);
                $base = $pde & 0xFFE00000;
                $phys = ($base + ($linear & 0x1FFFFF)) & 0xFFFFFFFF;
                return $phys;
            }

            $pteAddr = (($pde & 0xFFFFFF000) + ($tableIndex * 8)) & 0xFFFFFFFF;
            $pte = $this->readPhysical64($runtime, $pteAddr);
            if (($pte & 0x1) === 0) {
                $err = ($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0);
                throw new FaultException(0x0E, $err, 'Page table entry not present');
            }
            if (($pte & (~0x7FF)) !== 0) {
                $err = 0x08 | ($runtime->memoryAccessor()->shouldInstructionFetch() ? 0x10 : 0);
                throw new FaultException(0x0E, $err, 'Reserved bit set in PTE');
            }
            if ($user && (($pte & 0x4) === 0)) {
                $err = ($isWrite ? 0b10 : 0) | 0b100 | 0b1;
                throw new FaultException(0x0E, $err, 'Page table entry not user accessible');
            }
            if ($isWrite && (($pte & 0x2) === 0)) {
                $err = 0b10 | ($user ? 0b100 : 0) | 0b1;
                throw new FaultException(0x0E, $err, 'Page table entry not writable');
            }

            $pde |= 0x20;
            $this->writePhysical64($runtime, $pdeAddr, $pde);
            $pte |= 0x20;
            if ($isWrite) {
                $pte |= 0x40;
            }
            $this->writePhysical64($runtime, $pteAddr, $pte);

            $phys = ($pte & 0xFFFFFF000) + $offset;
            return $phys & 0xFFFFFFFF;
        }

        $cr3 = $runtime->memoryAccessor()->readControlRegister(3) & 0xFFFFF000;
        $dirIndex = ($linear >> 22) & 0x3FF;
        $tableIndex = ($linear >> 12) & 0x3FF;
        $offset = $linear & 0xFFF;

        $pdeAddr = ($cr3 + ($dirIndex * 4)) & 0xFFFFFFFF;
        $pde = $this->readPhysical32($runtime, $pdeAddr);
        $presentPde = ($pde & 0x1) !== 0;
        if (!$presentPde) {
            $err = ($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0);
            throw new FaultException(0x0E, $err, 'Page directory entry not present');
        }
        if (($pde & 0xFFFFFF000) === 0) {
            $err = 0x08 | ($runtime->memoryAccessor()->shouldInstructionFetch() ? 0x10 : 0);
            throw new FaultException(0x0E, $err, 'Reserved PDE bits');
        }
        if ($user && (($pde & 0x4) === 0)) {
            $err = ($isWrite ? 0b10 : 0) | 0b100 | 0b1;
            throw new FaultException(0x0E, $err, 'Page directory entry not user accessible');
        }
        if ($isWrite && (($pde & 0x2) === 0)) {
            $err = 0b10 | ($user ? 0b100 : 0) | 0b1;
            throw new FaultException(0x0E, $err, 'Page directory entry not writable');
        }

        $is4M = $pse && (($pde & (1 << 7)) !== 0);
        if ($is4M) {
            // 4MB page
            $base = $pde & 0xFFC00000;
            if ($user && (($pde & 0x4) === 0)) {
                $err = ($isWrite ? 0b10 : 0) | 0b100 | 0b1;
                throw new FaultException(0x0E, $err, '4MB PDE not user accessible');
            }
            if ($isWrite && (($pde & 0x2) === 0)) {
                $err = 0b10 | ($user ? 0b100 : 0) | 0b1;
                throw new FaultException(0x0E, $err, '4MB PDE not writable');
            }
            $pde |= 0x20;
            if ($isWrite) {
                $pde |= 0x40;
            }
            $this->writePhysical32($runtime, $pdeAddr, $pde);
            return ($base + ($linear & 0x3FFFFF)) & 0xFFFFFFFF;
        }

        $pteAddr = ($pde & 0xFFFFF000) + ($tableIndex * 4);
        $pte = $this->readPhysical32($runtime, $pteAddr);
        $presentPte = ($pte & 0x1) !== 0;
        if (!$presentPte) {
            $err = ($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0);
            throw new FaultException(0x0E, $err, 'Page table entry not present');
        }
        if (($pte & 0xFFFFFF000) === 0) {
            $err = 0x08 | ($runtime->memoryAccessor()->shouldInstructionFetch() ? 0x10 : 0);
            throw new FaultException(0x0E, $err, 'Reserved PTE bits');
        }
        if ($user && (($pte & 0x4) === 0)) {
            $err = ($isWrite ? 0b10 : 0) | 0b100 | 0b1;
            throw new FaultException(0x0E, $err, 'Page table entry not user accessible');
        }
        if ($isWrite && (($pte & 0x2) === 0)) {
            $err = 0b10 | ($user ? 0b100 : 0) | 0b1;
            throw new FaultException(0x0E, $err, 'Page table entry not writable');
        }

        // Set accessed/dirty bits when paging structures are present.
        if ($presentPde) {
            $pde |= 0x20; // accessed
            $this->writePhysical32($runtime, $pdeAddr, $pde);
        }
        if ($presentPte) {
            $pte |= 0x20; // accessed
            if ($isWrite) {
                $pte |= 0x40; // dirty
            }
            $this->writePhysical32($runtime, $pteAddr, $pte);
        }

        $phys = ($pte & 0xFFFFF000) + $offset;
        return $phys & 0xFFFFFFFF;
    }

    protected function updateCplFromSelector(RuntimeInterface $runtime, int $selector, ?int $overrideCpl = null, ?array $descriptor = null): void
    {
        $ctx = $runtime->context()->cpu();
        $normalized = $selector & 0xFFFF;

        if ($ctx->isProtectedMode()) {
            $descriptor ??= $this->readSegmentDescriptor($runtime, $normalized);
            if ($descriptor !== null && ($descriptor['present'] ?? false)) {
                $newCpl = $overrideCpl ?? $descriptor['dpl'];
                $ctx->setCpl($newCpl);
                $ctx->setUserMode($newCpl === 3);
                return;
            }
        }

        $newCpl = $overrideCpl ?? ($normalized & 0x3);
        $ctx->setCpl($newCpl);
        $ctx->setUserMode($newCpl === 3);
    }

    protected function writeCodeSegment(RuntimeInterface $runtime, int $selector, ?int $overrideCpl = null, ?array $descriptor = null): void
    {
        $normalized = $selector & 0xFFFF;
        $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit(RegisterType::CS, $normalized);
        $ctx = $runtime->context()->cpu();
        if ($ctx->isProtectedMode()) {
            $descriptor ??= $this->readSegmentDescriptor($runtime, $normalized);
        }
        $defaultSize = $descriptor['default'] ?? ($ctx->isProtectedMode() ? 32 : 16);
        $ctx->setDefaultOperandSize($defaultSize);
        $ctx->setDefaultAddressSize($defaultSize);
        $this->updateCplFromSelector($runtime, $normalized, $overrideCpl, $descriptor);
    }

    protected function resolveCodeDescriptor(RuntimeInterface $runtime, int $selector): array
    {
        $descriptor = $this->readSegmentDescriptor($runtime, $selector);
        if ($descriptor === null) {
            throw new FaultException(0x0D, $selector, sprintf('Invalid code selector 0x%04X', $selector));
        }
        if (!($descriptor['present'] ?? false)) {
            throw new FaultException(0x0B, $selector, sprintf('Code selector 0x%04X not present', $selector));
        }
        if ($descriptor['system'] ?? false) {
            throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not a code segment', $selector));
        }
        if (!($descriptor['executable'] ?? false)) {
            throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not executable', $selector));
        }
        return $descriptor;
    }

    protected function computeCplForTransfer(RuntimeInterface $runtime, int $selector, array $descriptor): int
    {
        $cpl = $runtime->context()->cpu()->cpl();
        $rpl = $selector & 0x3;
        $dpl = $descriptor['dpl'] ?? 0;
        $conforming = ($descriptor['type'] & 0x4) !== 0;

        if ($conforming) {
            if ($cpl < $dpl) {
                throw new FaultException(0x0D, $selector, sprintf('Conforming selector 0x%04X requires CPL >= DPL', $selector));
            }
            return $cpl;
        }

        if (max($cpl, $rpl) > $dpl) {
            throw new FaultException(0x0D, $selector, sprintf('Non-conforming selector 0x%04X privilege check failed', $selector));
        }

        return $dpl;
    }

    protected function packFlags(RuntimeInterface $runtime): int
    {
        $ma = $runtime->memoryAccessor();
        $flags =
            ($ma->shouldCarryFlag() ? 1 : 0) |
            0x2 | // reserved bit 1 set
            ($ma->shouldParityFlag() ? (1 << 2) : 0) |
            ($ma->shouldZeroFlag() ? (1 << 6) : 0) |
            ($ma->shouldSignFlag() ? (1 << 7) : 0) |
            ($ma->shouldInterruptFlag() ? (1 << 9) : 0) |
            ($ma->shouldDirectionFlag() ? (1 << 10) : 0) |
            ($ma->shouldOverflowFlag() ? (1 << 11) : 0);
        $flags |= ($runtime->context()->cpu()->iopl() & 0x3) << 12;
        if ($runtime->context()->cpu()->nt()) {
            $flags |= (1 << 14);
        }
        return $flags & 0xFFFFFFFF;
    }

    protected function applyFlags(RuntimeInterface $runtime, int $flags, int $size = 32): void
    {
        $ma = $runtime->memoryAccessor();
        $ma->setCarryFlag(($flags & 0x1) !== 0);
        $ma->setParityFlag(($flags & (1 << 2)) !== 0);
        $ma->setZeroFlag(($flags & (1 << 6)) !== 0);
        $ma->setSignFlag(($flags & (1 << 7)) !== 0);
        $ma->setOverflowFlag(($flags & (1 << 11)) !== 0);
        $ma->setDirectionFlag(($flags & (1 << 10)) !== 0);
        $ma->setInterruptFlag(($flags & (1 << 9)) !== 0);
        $runtime->context()->cpu()->setIopl(($flags >> 12) & 0x3);
        $runtime->context()->cpu()->setNt(($flags & (1 << 14)) !== 0);
    }

    protected function tss32Offsets(): array
    {
        return [
            'backlink' => 0x00,
            'esp0' => 0x04,
            'ss0' => 0x08,
            'esp1' => 0x0C,
            'ss1' => 0x10,
            'esp2' => 0x14,
            'ss2' => 0x18,
            'cr3' => 0x1C,
            'eip' => 0x20,
            'eflags' => 0x24,
            'eax' => 0x28,
            'ecx' => 0x2C,
            'edx' => 0x30,
            'ebx' => 0x34,
            'esp' => 0x38,
            'ebp' => 0x3C,
            'esi' => 0x40,
            'edi' => 0x44,
            'es' => 0x48,
            'cs' => 0x4C,
            'ss' => 0x50,
            'ds' => 0x54,
            'fs' => 0x58,
            'gs' => 0x5C,
            'ldtr' => 0x60,
            'iomap' => 0x66,
        ];
    }

    protected function assertIoPermission(RuntimeInterface $runtime, int $port, int $width): void
    {
        if (!$runtime->context()->cpu()->isProtectedMode()) {
            return;
        }

        $tr = $runtime->context()->cpu()->taskRegister();
        $trSelector = $tr['selector'] ?? 0;
        $trBase = $tr['base'] ?? 0;
        $trLimit = $tr['limit'] ?? 0;

        if ($trSelector === 0) {
            return; // no TSS loaded; allow
        }

        $tss32 = $this->tss32Offsets();
        // IO map base is word at TSS32 offset (iomap).
        $ioBase = $this->readMemory16($runtime, $trBase + $tss32['iomap']);
        if ($ioBase >= $trLimit) {
            return; // I/O bitmap beyond limit => all access allowed
        }

        $bytesNeeded = intdiv($width, 8);
        for ($i = 0; $i < $bytesNeeded; $i++) {
            $p = $port + $i;
            $byteOffset = $trBase + $ioBase + intdiv($p, 8);
            // If bitmap extends beyond limit, #TS(0)
            if (($byteOffset - $trBase) > $trLimit) {
                throw new FaultException(0x0A, $trSelector, 'I/O bitmap exceeds TSS limit');
            }
            $bit = $p & 0x7;
            $mask = 1 << $bit;
            $mapByte = $this->readMemory8($runtime, $byteOffset);
            if (($mapByte & $mask) !== 0) {
                throw new FaultException(0x0D, $trSelector, sprintf('I/O port 0x%04X not permitted', $p));
            }
        }
    }

    protected function readPort(RuntimeInterface $runtime, int $port, int $width): int
    {
        $runtime->option()->logger()->debug(sprintf('IN from port 0x%04X (%d-bit)', $port, $width));

        static $ata;
        $ata ??= new Ata($runtime);
        $ctx = $runtime->context()->cpu();
        $kbd = $ctx->keyboardController();
        $cmos = $ctx->cmos();
        $picState = $ctx->picState();
        static $pciConfigAddr = 0;
        static $pciConfigSpace = null;
        $pciConfigSpace ??= $this->defaultPciConfig();
        static $vga = null;
        $vga ??= [
            'seq_idx' => 0,
            'seq' => array_fill(0, 5, 0),
            'gfx_idx' => 0,
            'gfx' => array_fill(0, 9, 0),
            'crtc_idx' => 0,
            'crtc' => array_fill(0, 0x19, 0),
            'attr_idx' => 0,
            'attr' => array_fill(0, 0x15, 0),
            'misc_output' => 0x63,
            'feature' => 0,
            'flip_flop' => false,
        ];

        if ($port === 0x60) {
            return $kbd->readData($runtime);
        }

        if ($port === 0x64) {
            return $kbd->readStatus();
        }

        if ($port === 0x1F0 || $port === 0x170) {
            return $ata->readDataWord();
        }
        if ($port === 0x1F7 || $port === 0x177 || $port === 0x3F6 || $port === 0x376) {
            return $ata->readStatus();
        }
        if (($port >= 0x1F2 && $port <= 0x1F6) || ($port >= 0x172 && $port <= 0x176)) {
            return $ata->readRegister($port);
        }
        if ($port >= 0xCC00 && $port <= 0xCC07) {
            return $ata->readBusMaster($port);
        }

        if ($port === 0x21) {
            return $picState->imrMaster;
        }
        if ($port === 0xA1) {
            return $picState->imrSlave;
        }

        if (in_array($port, [0x40, 0x41, 0x42, 0x43], true)) {
            return Pit::shared()->readCounter();
        }

        if ($port === 0x20) {
            return $picState->readCommandPort(false);
        }
        if ($port === 0xA0) {
            return $picState->readCommandPort(true);
        }

        if ($port === 0x92) {
            return $runtime->context()->cpu()->isA20Enabled() ? 0x02 : 0x00;
        }

        if ($port === 0xCF8) {
            return $pciConfigAddr;
        }
        if ($port === 0xCFC) {
            $bus = ($pciConfigAddr >> 16) & 0xFF;
            $dev = ($pciConfigAddr >> 11) & 0x1F;
            $func = ($pciConfigAddr >> 8) & 0x7;
            $reg = $pciConfigAddr & 0xFC;
            $val = $this->readPciConfig($pciConfigSpace, $bus, $dev, $func, $reg);
            return $val;
        }

        // VGA read stubs
        if ($port === 0x3C2 || $port === 0x3CC) {
            return $vga['misc_output'];
        }
        if ($port === 0x3C4) {
            return $vga['seq_idx'];
        }
        if ($port === 0x3C5) {
            return $vga['seq'][$vga['seq_idx']] ?? 0;
        }
        if ($port === 0x3CE) {
            return $vga['gfx_idx'];
        }
        if ($port === 0x3CF) {
            return $vga['gfx'][$vga['gfx_idx']] ?? 0;
        }
        if ($port === 0x3D4 || $port === 0x3B4) {
            return $vga['crtc_idx'];
        }
        if ($port === 0x3D5 || $port === 0x3B5) {
            return $vga['crtc'][$vga['crtc_idx']] ?? 0;
        }
        if ($port === 0x3C0) {
            $vga['flip_flop'] = !$vga['flip_flop'];
            return 0;
        }
        if ($port === 0x3C1) {
            return $vga['attr'][$vga['attr_idx']] ?? 0;
        }
        if ($port === 0x3DA) {
            $vga['flip_flop'] = false;
            return 0x09;
        }

        if (in_array($port, [0x70, 0x71], true)) {
            return $port === 0x71 ? $cmos->read() : 0;
        }

        if ($port === 0x3F8) {
            return 0;
        }

        return 0;
    }

    private function defaultPciConfig(): array
    {
        $cfg = [
            '0:0:0' => [
                0x00 => 0x12378086, // device/vendor
                0x04 => 0x00000000, // command/status
                0x08 => 0x00060000, // class code host bridge
                0x3C => 0x00000000, // interrupt line/pin
            ],
            '0:1f:0' => [
                0x00 => 0x70008086, // ISA bridge
                0x04 => 0x00000000,
                0x08 => 0x00060100,
                0x3C => 0x00000000,
            ],
            '0:1f:1' => [
                0x00 => 0x70108086, // IDE
                0x04 => 0x00000000,
                0x08 => 0x00010180, // IDE controller, legacy mode
                0x10 => 0x000001F0, // BAR0 legacy
                0x14 => 0x000003F4, // BAR1
                0x18 => 0x00000170, // BAR2
                0x1C => 0x00000374, // BAR3
                0x20 => 0x0000CC00, // BAR4 (bus master IDE, dummy)
                0x3C => 0x00000E01, // interrupt line 14, pin INTA
            ],
            '0:2:0' => [
                0x00 => 0x11111234, // VGA vendor/device (bochs-like)
                0x04 => 0x00000000,
                0x08 => 0x00030000, // VGA display controller
                0x10 => 0xE0000000, // BAR0 prefetchable framebuffer (dummy)
                0x14 => 0x00000000, // BAR1
                0x3C => 0x00000000, // interrupt line/pin
            ],
        ];
        return $cfg;
    }

    private function readPciConfig(array $space, int $bus, int $dev, int $func, int $reg): int
    {
        $key = sprintf('%d:%d:%d', $bus, $dev, $func);
        $table = $space[$key] ?? null;
        if ($table === null) {
            return 0xFFFFFFFF;
        }

        $regAligned = $reg & 0xFC;
        return $table[$regAligned] ?? 0xFFFFFFFF;
    }

    private function writePciConfig(array &$space, int $bus, int $dev, int $func, int $reg, int $value, int $width): void
    {
        $key = sprintf('%d:%d:%d', $bus, $dev, $func);
        if (!isset($space[$key])) {
            return;
        }
        $regAligned = $reg & 0xFC;
        $current = $space[$key][$regAligned] ?? 0;
        $shift = ($reg & 0x3) * 8;
        $mask = match ($width) {
            8 => 0xFF << $shift,
            16 => 0xFFFF << $shift,
            default => 0xFFFFFFFF,
        };
        $newVal = ($current & ~$mask) | (($value << $shift) & $mask);
        // keep device/vendor readonly
        if ($regAligned === 0x00) {
            return;
        }
        $space[$key][$regAligned] = $newVal & 0xFFFFFFFF;
    }

    protected function writePort(RuntimeInterface $runtime, int $port, int $value, int $width): void
    {
        $mask = $width === 8 ? 0xFF : ($width === 16 ? 0xFFFF : 0xFFFFFFFF);
        $value &= $mask;
        $runtime->option()->logger()->debug(sprintf('OUT to port 0x%04X value 0x%X (%d-bit)', $port, $value, $width));

        $ctx = $runtime->context()->cpu();
        $picState = $ctx->picState();
        static $ata;
        $ata ??= new Ata($runtime);
        $pit = Pit::shared();
        $kbd = $ctx->keyboardController();
        $cmos = $ctx->cmos();
        static $pciConfigAddr = 0;
        static $pciConfigSpace = null;
        $pciConfigSpace ??= $this->defaultPciConfig();
        static $vga = null;
        $vga ??= [
            'seq_idx' => 0,
            'seq' => array_fill(0, 5, 0),
            'gfx_idx' => 0,
            'gfx' => array_fill(0, 9, 0),
            'crtc_idx' => 0,
            'crtc' => array_fill(0, 0x19, 0),
            'attr_idx' => 0,
            'attr' => array_fill(0, 0x15, 0),
            'misc_output' => 0x63,
            'feature' => 0,
            'flip_flop' => false,
        ];

        if ($port === 0x3F8) {
            $runtime->option()->IO()->output()->write(chr($value & 0xFF));
            return;
        }

        if ($port === 0x92) {
            $runtime->context()->cpu()->enableA20(($value & 0x02) !== 0);
            return;
        }

        if ($port === 0xCF8) {
            $pciConfigAddr = $value;
            return;
        }
        if ($port === 0xCFC) {
            $bus = ($pciConfigAddr >> 16) & 0xFF;
            $dev = ($pciConfigAddr >> 11) & 0x1F;
            $func = ($pciConfigAddr >> 8) & 0x7;
            $reg = $pciConfigAddr & 0xFC;
            $this->writePciConfig($pciConfigSpace, $bus, $dev, $func, $reg, $value, $width);
            return;
        }

        if ($port === 0x1F0 || $port === 0x170) {
            // Data port - write word to ATA
            $ata->writeDataWord($value);
            return;
        }
        if (($port >= 0x1F1 && $port <= 0x1F7) || ($port >= 0x171 && $port <= 0x177)) {
            $ata->writeRegister($port, $value);
            return;
        }
        if ($port >= 0xCC00 && $port <= 0xCC07) {
            $ata->writeBusMaster($port, $value);
            return;
        }

        // VGA writes
        if ($port === 0x3C2) { // Misc output write
            $vga['misc_output'] = $value & 0xFF;
            return;
        }
        if ($port === 0x3C4) { // sequencer index
            $vga['seq_idx'] = $value & 0x1F;
            return;
        }
        if ($port === 0x3C5) {
            $vga['seq'][$vga['seq_idx']] = $value & 0xFF;
            return;
        }
        if ($port === 0x3CE) {
            $vga['gfx_idx'] = $value & 0x1F;
            return;
        }
        if ($port === 0x3CF) {
            $vga['gfx'][$vga['gfx_idx']] = $value & 0xFF;
            return;
        }
        if ($port === 0x3D4 || $port === 0x3B4) {
            $vga['crtc_idx'] = $value & 0x3F;
            return;
        }
        if ($port === 0x3D5 || $port === 0x3B5) {
            $vga['crtc'][$vga['crtc_idx']] = $value & 0xFF;
            return;
        }
        if ($port === 0x3C0) {
            if ($vga['flip_flop'] === false) {
                $vga['attr_idx'] = $value & 0x1F;
            } else {
                $vga['attr'][$vga['attr_idx']] = $value & 0xFF;
            }
            $vga['flip_flop'] = !$vga['flip_flop'];
            return;
        }
        if ($port === 0x3C3) { // feature control
            $vga['feature'] = $value & 0xFF;
            return;
        }
        if ($port === 0x3DA) {
            $vga['flip_flop'] = false;
            return;
        }

        if ($port === 0x20) {
            $picState->writeCommandMaster($value & 0xFF);
            return;
        }
        if ($port === 0xA0) {
            $picState->writeCommandSlave($value & 0xFF);
            return;
        }
        if ($port === 0x21) {
            $picState->writeDataMaster($value & 0xFF);
            return;
        }
        if ($port === 0xA1) {
            $picState->writeDataSlave($value & 0xFF);
            return;
        }

        if (in_array($port, [0x40, 0x41, 0x42], true)) {
            $pit->writeChannel($port - 0x40, $value & 0xFF);
            return;
        }
        if ($port === 0x43) {
            $pit->writeControl($value & 0xFF);
            return;
        }

        if ($port === 0x64) {
            $kbd->writeCommand($value, $runtime);
            return;
        }
        if ($port === 0x60) {
            $kbd->writeDataPort($value, $runtime);
            return;
        }

        if ($port === 0x70) {
            $cmos->writeIndex($value & 0xFF);
            return;
        }

        if (in_array($port, [0x20, 0x21, 0xA0, 0xA1], true)) {
            return;
        }

        if (in_array($port, [0x70, 0x71], true)) {
            return;
        }
    }

    protected function readPhysical8(RuntimeInterface $runtime, int $address): int
    {
        $mmio = $this->readMmio($runtime, $address, 8);
        if ($mmio !== null) {
            return $mmio;
        }

        // Try to read raw byte from memory
        $value = $runtime->memoryAccessor()->readRawByte($address);
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

    protected function readPhysical16(RuntimeInterface $runtime, int $address): int
    {
        $mmio = $this->readMmio($runtime, $address, 16);
        if ($mmio !== null) {
            return $mmio;
        }

        // Read two consecutive bytes from memory and combine them in little-endian order
        $lo = $this->readPhysical8($runtime, $address);
        $hi = $this->readPhysical8($runtime, $address + 1);
        return ($hi << 8) | $lo;
    }

    protected function readPhysical32(RuntimeInterface $runtime, int $address): int
    {
        $mmio = $this->readMmio($runtime, $address, 32);
        if ($mmio !== null) {
            return $mmio;
        }

        // Read four consecutive bytes from memory and combine them in little-endian order
        $b0 = $this->readPhysical8($runtime, $address);
        $b1 = $this->readPhysical8($runtime, $address + 1);
        $b2 = $this->readPhysical8($runtime, $address + 2);
        $b3 = $this->readPhysical8($runtime, $address + 3);
        return $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);
    }

    private function readMmio(RuntimeInterface $runtime, int $address, int $width): ?int
    {
        $apic = $runtime->context()->cpu()->apicState();
        if ($address >= 0xFEE00000 && $address < 0xFEE01000) {
            $offset = $address - 0xFEE00000;
            return $apic->readLapic($offset, $width);
        }
        if ($address >= 0xFEC00000 && $address < 0xFEC00020) {
            $offset = $address - 0xFEC00000;
            if ($offset === 0x00) {
                return $apic->readIoapicIndex();
            }
            if ($offset === 0x10) {
                return $apic->readIoapicData();
            }
            return 0;
        }

        return null;
    }

    private function writeMmio(RuntimeInterface $runtime, int $address, int $value, int $width): bool
    {
        $apic = $runtime?->context()->cpu()->apicState() ?? null;
        if ($apic === null) {
            return false;
        }

        if ($address >= 0xFEE00000 && $address < 0xFEE01000) {
            $offset = $address - 0xFEE00000;
            $apic->writeLapic($offset, $value, $width);
            return true;
        }

        if ($address >= 0xFEC00000 && $address < 0xFEC00020) {
            $offset = $address - 0xFEC00000;
            if ($offset === 0x00) {
                $apic->writeIoapicIndex($value);
            } elseif ($offset === 0x10) {
                $apic->writeIoapicData($value);
            }
            return true;
        }

        return false;
    }

    protected function readPhysical64(RuntimeInterface $runtime, int $address): int
    {
        $lo = $this->readPhysical32($runtime, $address);
        $hi = $this->readPhysical32($runtime, $address + 4);
        return ($hi << 32) | $lo;
    }

    protected function writePhysical64(RuntimeInterface $runtime, int $address, int $value): void
    {
        $lo = $value & 0xFFFFFFFF;
        $hi = ($value >> 32) & 0xFFFFFFFF;
        $this->writePhysical32($runtime, $address, $lo);
        $this->writePhysical32($runtime, $address + 4, $hi);
    }

    protected function writePhysical32(RuntimeInterface $runtime, int $address, int $value): void
    {
        $runtime->memoryAccessor()->allocate($address, 4, safe: false);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($address, $value, 32);
    }
}
