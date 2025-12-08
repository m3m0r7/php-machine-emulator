<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Trait for address calculation operations.
 * Handles effective address computation for 16-bit, 32-bit, and 64-bit modes.
 * Used by both x86 and x86_64 instructions.
 */
trait AddressingTrait
{
    /**
     * Get default segment for 16-bit addressing mode.
     */
    protected function defaultSegmentFor16(ModRegRMInterface $modRegRM): RegisterType
    {
        $mode = ModType::from($modRegRM->mode());
        $rm = $modRegRM->registerOrMemoryAddress();

        $usesSS = in_array($rm, [0b010, 0b011], true)
            || ($rm === 0b110 && $mode !== ModType::NO_DISPLACEMENT_OR_16BITS_DISPLACEMENT);

        return $usesSS ? RegisterType::SS : RegisterType::DS;
    }

    /**
     * Get effective address and default segment info.
     *
     * @return array{int, RegisterType} [offset, defaultSegment]
     */
    protected function effectiveAddressInfo(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): array
    {
        $addrSize = $runtime->context()->cpu()->addressSize();

        if ($addrSize === 64) {
            return $this->effectiveAddressAndSegment64($runtime, $reader, $modRegRM);
        }

        if ($addrSize === 32) {
            return $this->effectiveAddressAndSegment32($runtime, $reader, $modRegRM);
        }

        $offset = $this->effectiveAddress16($runtime, $reader, $modRegRM);
        $seg = $this->defaultSegmentFor16($modRegRM);

        // Debug: log 16-bit addressing
        $rm = $modRegRM->registerOrMemoryAddress();
        if ($rm === 0b100) {
            $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asByte();
            $mode = ModType::from($modRegRM->mode());
            $runtime->option()->logger()->debug(sprintf(
                'effectiveAddress16 [SI]: ESI=0x%08X si_byte=0x%04X offset=0x%04X addrSize=%d mode=%s',
                $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize(32),
                $si,
                $offset,
                $addrSize,
                $mode->name
            ));
        }

        return [$offset, $seg];
    }

    /**
     * Calculate linear address from ModR/M.
     */
    protected function rmLinearAddress(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM, RegisterType|null $segmentOverride = null): int
    {
        [$offset, $defaultSegment] = $this->effectiveAddressInfo($runtime, $reader, $modRegRM);
        $cpuOverride = $runtime->context()->cpu()->segmentOverride();
        $segment = $segmentOverride ?? $cpuOverride ?? $defaultSegment;

        $linear = $this->segmentOffsetAddress($runtime, $segment, $offset);

        // Debug: log when accessing high memory in real mode
        if (!$runtime->context()->cpu()->isProtectedMode() && $offset >= 0x100000) {
            $segVal = $runtime->memoryAccessor()->fetch($segment)->asByte();
            $runtime->option()->logger()->debug(sprintf(
                'rmLinearAddress HIGH: offset=0x%08X segment=%s(0x%04X) linear=0x%08X A20=%d',
                $offset, $segment->name, $segVal, $linear,
                $runtime->context()->cpu()->isA20Enabled() ? 1 : 0
            ));
        }

        return $linear;
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

        // Debug: log [DI] addressing when DI has upper bits set
        if ($rm === 0b101) {
            $fullEdi = $runtime->memoryAccessor()->fetch(RegisterType::EDI)->asBytesBySize(32);
            $di16 = $val($runtime, RegisterType::EDI);
            if (($fullEdi & 0xFFFF0000) !== 0) {
                $runtime->option()->logger()->debug(sprintf(
                    'effectiveAddress16 [DI]: fullEDI=0x%08X di16=0x%04X address=0x%04X disp=%d result=0x%04X',
                    $fullEdi, $di16, $address, $disp, ($address + $disp) & 0xFFFF
                ));
            }
        }

        return ($address + $disp) & 0xFFFF;
    }

    /**
     * 32-bit effective address with default segment.
     *
     * @return array{int, RegisterType} [offset, segment]
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

        // x86 encoding order: ModR/M -> SIB (if present) -> Displacement
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

        // In 32-bit mode, all values and calculations must be masked to 32 bits
        // The index value from a register might appear as a large positive number in PHP
        // but should be treated as a signed 32-bit value for address calculation
        // Note: Always use 32-bit mask here - the final masking for real mode happens in
        // segmentOffsetAddress, which also handles Big Real Mode (Unreal Mode) support
        $mask = 0xFFFFFFFF;

        // Mask all components to 32 bits before calculation
        $baseVal32 = $baseVal & 0xFFFFFFFF;
        $indexVal32 = $indexVal & 0xFFFFFFFF;
        $scaledIndex = ($indexVal32 * $scale) & 0xFFFFFFFF;

        // Perform addition with 32-bit wraparound
        $offset = ($baseVal32 + $scaledIndex + $disp) & $mask;

        return [$offset, $defaultSegment];
    }

    /**
     * 64-bit effective address with default segment.
     * Includes support for RIP-relative addressing and REX prefix.
     *
     * @return array{int, RegisterType} [offset, segment]
     */
    protected function effectiveAddressAndSegment64(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): array
    {
        $mode = ModType::from($modRegRM->mode());
        $rm = $modRegRM->registerOrMemoryAddress();
        $cpu = $runtime->context()->cpu();
        $rexB = $cpu->rexB();
        $rexX = $cpu->rexX();
        $disp = 0;
        $baseVal = 0;
        $indexVal = 0;
        $scale = 0;
        $defaultSegment = RegisterType::DS;

        // Map of 64-bit register codes (with REX.B extension)
        $regVal64 = function (RuntimeInterface $runtime, int $code, bool $rexExt) use ($cpu): int {
            $fullCode = $rexExt ? ($code | 0b1000) : $code;
            $map = [
                0 => RegisterType::EAX,  // RAX
                1 => RegisterType::ECX,  // RCX
                2 => RegisterType::EDX,  // RDX
                3 => RegisterType::EBX,  // RBX
                4 => RegisterType::ESP,  // RSP
                5 => RegisterType::EBP,  // RBP
                6 => RegisterType::ESI,  // RSI
                7 => RegisterType::EDI,  // RDI
                8 => RegisterType::R8,
                9 => RegisterType::R9,
                10 => RegisterType::R10,
                11 => RegisterType::R11,
                12 => RegisterType::R12,
                13 => RegisterType::R13,
                14 => RegisterType::R14,
                15 => RegisterType::R15,
            ];
            return $runtime->memoryAccessor()->fetch($map[$fullCode])->asBytesBySize(64);
        };

        // Determine actual rm with REX.B
        $rmWithRex = $rexB ? ($rm | 0b1000) : $rm;

        if ($rm === 0b100) {
            // SIB byte present
            $sib = $reader->byteAsSIB();
            $scale = 1 << $sib->scale();

            $baseCode = $rexB ? ($sib->base() | 0b1000) : $sib->base();
            $indexCode = $rexX ? ($sib->index() | 0b1000) : $sib->index();

            // Index = 4 (RSP/R12 without REX.X) means no index
            $indexVal = ($sib->index() === 0b100 && !$rexX) ? 0 : $regVal64($runtime, $sib->index(), $rexX);

            // Read displacement after SIB
            if ($mode === ModType::SIGNED_8BITS_DISPLACEMENT) {
                $disp = $reader->streamReader()->signedByte();
            } elseif ($mode === ModType::SIGNED_16BITS_DISPLACEMENT) {
                $disp = $reader->signedDword();
            }

            // Base = 5 (RBP/R13) with mod=00 means disp32 only (no base)
            if ($sib->base() === 0b101 && $mode === ModType::NO_DISPLACEMENT_OR_16BITS_DISPLACEMENT) {
                $disp = $reader->signedDword();
            } else {
                $baseVal = $regVal64($runtime, $sib->base(), $rexB);
                // RSP/R12 or RBP/R13 use SS segment by default
                $defaultSegment = in_array($baseCode, [4, 5, 12, 13], true) ? RegisterType::SS : RegisterType::DS;
            }
        } else {
            // No SIB
            if ($mode === ModType::SIGNED_8BITS_DISPLACEMENT) {
                $disp = $reader->streamReader()->signedByte();
            } elseif ($mode === ModType::SIGNED_16BITS_DISPLACEMENT) {
                $disp = $reader->signedDword();
            }

            // mod=00, rm=5 in 64-bit mode is RIP-relative addressing
            if ($mode === ModType::NO_DISPLACEMENT_OR_16BITS_DISPLACEMENT && $rm === 0b101) {
                $disp = $reader->signedDword();
                // RIP-relative: address = RIP + disp32
                $rip = $runtime->memory()->offset();
                $offset = ($rip + $disp) & 0xFFFFFFFFFFFFFFFF;
                return [$offset, RegisterType::DS];
            } else {
                $baseVal = $regVal64($runtime, $rm, $rexB);
                $defaultSegment = in_array($rmWithRex, [4, 5, 12, 13], true) ? RegisterType::SS : RegisterType::DS;
            }
        }

        $offset = ($baseVal + $indexVal * $scale + $disp) & 0xFFFFFFFFFFFFFFFF;

        return [$offset, $defaultSegment];
    }

    // Abstract methods that must be implemented by using class/trait
    abstract protected function segmentOffsetAddress(RuntimeInterface $runtime, RegisterType $segment, int $offset): int;
}
