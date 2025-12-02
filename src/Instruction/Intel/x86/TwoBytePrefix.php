<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class TwoBytePrefix implements InstructionInterface
{
    use Instructable;
    private static array $msr = [];
    private array $xmm = [];
    private int $mxcsr = 0x1F80; // default SSE control/status

    public function opcodes(): array
    {
        return [0x0F];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $next = $reader->streamReader()->byte();

        return match ($next) {
            0x00 => $this->group0($runtime, $reader),
            0x20 => $this->movFromControl($runtime, $reader),
            0x22 => $this->movToControl($runtime, $reader),
            0x01 => $this->group6($runtime, $reader),
            0xA0 => $this->pushFsGs($runtime, RegisterType::FS),
            0xA8 => $this->pushFsGs($runtime, RegisterType::GS),
            0xA1 => $this->popFsGs($runtime, RegisterType::FS),
            0xA9 => $this->popFsGs($runtime, RegisterType::GS),
            0xA2 => $this->cpuid($runtime),
            0xB0 => $this->cmpxchg($runtime, $reader, true),
            0xB1 => $this->cmpxchg($runtime, $reader, false),
            0xC0 => $this->xadd($runtime, $reader, true),
            0xC1 => $this->xadd($runtime, $reader, false),
            0xC8, 0xC9, 0xCA, 0xCB, 0xCC, 0xCD, 0xCE, 0xCF => $this->bswap($runtime, $next),
            0x31 => $this->rdtsc($runtime),
            0xC7 => $this->cmpxchg8b($runtime, $reader),
            0x06 => $this->clts($runtime),
            0xAE => $this->fxsaveFxrstor($runtime, $reader),
            0x80, 0x81, 0x82, 0x83, 0x84, 0x85, 0x86, 0x87,
            0x88, 0x89, 0x8A, 0x8B, 0x8C, 0x8D, 0x8E, 0x8F => $this->jccNear($runtime, $next),
            0x30 => $this->wrmsr($runtime),
            0x32 => $this->rdmsr($runtime),
            0x34 => $this->sysenter($runtime),
            0x35 => $this->sysexit($runtime),
            0xB6 => $this->movzx($runtime, $reader, true),
            0xB7 => $this->movzx($runtime, $reader, false),
            0xBE => $this->movsx($runtime, $reader, true),
            0xBF => $this->movsx($runtime, $reader, false),
            0xAF => $this->imulRegRm($runtime, $reader),
            0xA4 => $this->shld($runtime, $reader, true),
            0xA5 => $this->shld($runtime, $reader, false),
            0xAC => $this->shrd($runtime, $reader, true),
            0xAD => $this->shrd($runtime, $reader, false),
            0xA3 => $this->bitOp($runtime, $reader, 'bt', false, null),
            0xAB => $this->bitOp($runtime, $reader, 'bts', false, null),
            0xB3 => $this->bitOp($runtime, $reader, 'btr', false, null),
            0xBB => $this->bitOp($runtime, $reader, 'btc', false, null),
            0xBA => $this->bitOp($runtime, $reader, null, true, null),
            0xBC => $this->bsf($runtime, $reader),
            0xBD => $this->bsr($runtime, $reader),
            0x40, 0x41, 0x42, 0x43, 0x44, 0x45, 0x46, 0x47,
            0x48, 0x49, 0x4A, 0x4B, 0x4C, 0x4D, 0x4E, 0x4F => $this->cmovcc($runtime, $reader, $next),
            0x90, 0x91, 0x92, 0x93, 0x94, 0x95, 0x96, 0x97,
            0x98, 0x99, 0x9A, 0x9B, 0x9C, 0x9D, 0x9E, 0x9F => $this->setcc($runtime, $reader, $next),
            0x1F => $this->nopModrm($runtime, $reader),
            // SSE-ish stubs: treat as no-ops while consuming ModRM/displacement
            0x10, 0x11, 0x12, 0x13, 0x14, 0x15, 0x16, 0x17,
            0x28, 0x29, 0x2A, 0x2B, 0x2C, 0x2D, 0x2E, 0x2F,
            0x6E, 0x6F, 0x7E, 0x7F,
            0x50, 0x51, 0x52, 0x53, 0x54, 0x55, 0x56, 0x57,
            0x58, 0x59, 0x5A, 0x5B, 0x5C, 0x5D, 0x5E, 0x5F,
            0xD0, 0xD1, 0xD2, 0xD3, 0xD4, 0xD5, 0xD6, 0xD7,
            0xD8, 0xD9, 0xDA, 0xDB, 0xDC, 0xDD, 0xDE, 0xDF,
            0xE0, 0xE1, 0xE2, 0xE3, 0xE4, 0xE5, 0xE6, 0xE7,
            0xE8, 0xE9, 0xEA, 0xEB, 0xEC, 0xED, 0xEE, 0xEF,
            0xF0, 0xF1, 0xF2, 0xF3, 0xF4, 0xF5, 0xF6, 0xF7,
            0xF8, 0xF9, 0xFA, 0xFB, 0xFC, 0xFD, 0xFE, 0xFF,
            0xC4, 0xC5 => $this->sseNoOp($runtime, $reader, false, $next),
            0xC2, 0x70, 0x71, 0x72, 0x73, 0xC6 => $this->sseNoOp($runtime, $reader, true, $next),
            0x77 => ExecutionStatus::SUCCESS, // EMMS no-op
            0x0B => throw new FaultException(6, 0, 'UD2'), // invalid opcode
            0x08, 0x09 => ExecutionStatus::SUCCESS, // INVD/WBINVD no-op
            default => ExecutionStatus::SUCCESS, // unimplemented 2-byte opcode acts as NOP for now
        };
    }

    private function movFromControl(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        if (ModType::from($modrm->mode()) !== ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException('MOV from CR requires register addressing');
        }
        $cr = $modrm->registerOrOPCode() & 0b111;
        $val = $runtime->memoryAccessor()->readControlRegister($cr);
        $size = $runtime->context()->cpu()->operandSize();
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($modrm->registerOrMemoryAddress(), $val, $size);
        return ExecutionStatus::SUCCESS;
    }

    private function movToControl(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        if (ModType::from($modrm->mode()) !== ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException('MOV to CR requires register addressing');
        }
        $cr = $modrm->registerOrOPCode() & 0b111;
        $size = $runtime->context()->cpu()->operandSize();
        $val = $runtime->memoryAccessor()->fetch($modrm->registerOrMemoryAddress())->asBytesBySize($size);
        if ($cr === 0) {
            $val |= 0x22; // MP + NE set so kernel assumes FPU present
        }
        $runtime->memoryAccessor()->writeControlRegister($cr, $val);

        if ($cr === 0) {
            // update protected mode flag from CR0.PE
            $runtime->context()->cpu()->setProtectedMode((bool) ($val & 0x1));
            $runtime->context()->cpu()->setPagingEnabled((bool) ($val & 0x80000000));
        }
        if ($cr === 3 && $runtime->context()->cpu()->isPagingEnabled()) {
            // refresh paging flag; CR0 might have been set earlier
            $runtime->context()->cpu()->setPagingEnabled(true);
        }
        if ($cr === 4 && $runtime->context()->cpu()->isPagingEnabled()) {
            $runtime->context()->cpu()->setPagingEnabled(true);
        }

        return ExecutionStatus::SUCCESS;
    }

    private function cpuid(RuntimeInterface $runtime): ExecutionStatus
    {
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $leaf = $ma->fetch(RegisterType::EAX)->asBytesBySize(32);

        switch ($leaf) {
            case 0x0:
                // Max leaf 7, vendor "GenuineIntel"
                $ma->writeBySize(RegisterType::EAX, 0x00000007, 32);
                $ma->writeBySize(RegisterType::EBX, 0x756E6547, 32); // "Genu"
                $ma->writeBySize(RegisterType::EDX, 0x49656E69, 32); // "ineI"
                $ma->writeBySize(RegisterType::ECX, 0x6C65746E, 32); // "ntel"
                break;
            case 0x1:
                // Basic feature bits: report PSE + CMPXCHG8B + TSC + PAE + FPU present + APIC
                $ma->writeBySize(RegisterType::EAX, 0x00000601, 32); // family 6, model/stepping minimal (i686 class)
                $ma->writeBySize(RegisterType::EBX, 0, 32);
                $ma->writeBySize(RegisterType::ECX, 0, 32);
                $features = (1 << 0) // FPU
                    | (1 << 1) // VME
                    | (1 << 2) // DE
                    | (1 << 3) // PSE
                    | (1 << 4) // TSC
                    | (1 << 5) // MSR
                    | (1 << 11) // SEP (SYSENTER/SYSEXIT)
                    | (1 << 9) // APIC
                    | (1 << 6) // PAE
                    | (1 << 7) // MCE
                    | (1 << 8) // CMPXCHG8B
                    | (1 << 12) // MTRR (advertise for PAT-like probing)
                    | (1 << 16) // PAT
                    | (1 << 19) // CLFSH
                    | (1 << 13) // PGE
                    | (1 << 15) // CMOV
                    | (1 << 17) // PSE-36 (ignored but advertised)
                    | (1 << 23) // MMX
                    | (1 << 24) // FXSR
                    | (1 << 25) // SSE
                    | (1 << 26); // SSE2
                $ma->writeBySize(RegisterType::EDX, $features, 32);
                break;
            case 0x2:
                // Basic cache/TLB descriptor leaf (minimal stub)
                $ma->writeBySize(RegisterType::EAX, 0x04020101, 32);
                $ma->writeBySize(RegisterType::EBX, 0, 32);
                $ma->writeBySize(RegisterType::ECX, 0, 32);
                $ma->writeBySize(RegisterType::EDX, 0, 32);
                break;
            case 0x4:
                // Deterministic cache parameters: return no caches
                $ma->writeBySize(RegisterType::EAX, 0, 32);
                $ma->writeBySize(RegisterType::EBX, 0, 32);
                $ma->writeBySize(RegisterType::ECX, 0, 32);
                $ma->writeBySize(RegisterType::EDX, 0, 32);
                break;
            case 0x7:
                // Structured extended feature flags (subleaf 0)
                $ma->writeBySize(RegisterType::EAX, 0, 32);
                $ma->writeBySize(RegisterType::EBX, 0, 32);
                $ma->writeBySize(RegisterType::ECX, 0, 32);
                $ma->writeBySize(RegisterType::EDX, 0, 32);
                break;
            case 0x80000000:
                $ma->writeBySize(RegisterType::EAX, 0x80000008, 32);
                $ma->writeBySize(RegisterType::EBX, 0, 32);
                $ma->writeBySize(RegisterType::ECX, 0, 32);
                $ma->writeBySize(RegisterType::EDX, 0, 32);
                break;
            case 0x80000001:
                $ma->writeBySize(RegisterType::EAX, 0, 32);
                $ma->writeBySize(RegisterType::EBX, 0, 32);
                $ma->writeBySize(RegisterType::ECX, 0, 32);
                // Advertise NX (bit 20) and PAT (bit 16) in extended features.
                $ma->writeBySize(RegisterType::EDX, (1 << 20) | (1 << 16), 32);
                break;
            case 0x80000002:
            case 0x80000003:
            case 0x80000004:
                // Brand string "PHP Emulator CPU"
                $brand = str_pad('PHP Emulator CPU', 48, "\0");
                $chunk = (int) ($leaf - 0x80000002);
                $start = $chunk * 16;
                $slice = substr($brand, $start, 16);
                $vals = array_values(unpack('V4', $slice));
                $ma->writeBySize(RegisterType::EAX, $vals[0] ?? 0, 32);
                $ma->writeBySize(RegisterType::EBX, $vals[1] ?? 0, 32);
                $ma->writeBySize(RegisterType::ECX, $vals[2] ?? 0, 32);
                $ma->writeBySize(RegisterType::EDX, $vals[3] ?? 0, 32);
                break;
            case 0x80000008:
                // Physical address bits 36, virtual 32, no extra cores
                $ma->writeBySize(RegisterType::EAX, 0x00002420, 32);
                $ma->writeBySize(RegisterType::EBX, 0, 32);
                $ma->writeBySize(RegisterType::ECX, 0, 32);
                $ma->writeBySize(RegisterType::EDX, 0, 32);
                break;
            default:
                $ma->writeBySize(RegisterType::EAX, 0, 32);
                $ma->writeBySize(RegisterType::EBX, 0, 32);
                $ma->writeBySize(RegisterType::ECX, 0, 32);
                $ma->writeBySize(RegisterType::EDX, 0, 32);
                break;
        }

        return ExecutionStatus::SUCCESS;
    }

    private function cmpxchg(RuntimeInterface $runtime, EnhanceStreamReader $reader, bool $isByte): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();
        $mask = $opSize === 32 ? 0xFFFFFFFF : (($opSize === 16) ? 0xFFFF : 0xFF);

        // Pre-compute address to avoid consuming displacement twice
        $isRegister = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        $linearAddr = $isRegister ? null : $this->rmLinearAddress($runtime, $reader, $modrm);

        $acc = $isByte
            ? $ma->fetch(RegisterType::EAX)->asLowBit()
            : $ma->fetch(RegisterType::EAX)->asBytesBySize($opSize);

        // Read dest using pre-computed address
        if ($isByte) {
            $dest = $isRegister
                ? $this->read8BitRegister($runtime, $modrm->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
        } else {
            $dest = $isRegister
                ? $this->readRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $opSize)
                : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        }

        $src = $isByte
            ? $this->readRegisterBySize($runtime, $modrm->registerOrOPCode(), 8)
            : $this->readRegisterBySize($runtime, $modrm->registerOrOPCode(), $opSize);

        // Flags as a subtraction dest - acc.
        $ma->updateFlags($dest - $acc, $opSize)->setCarryFlag($dest < $acc);

        if ($dest === ($acc & $mask)) {
            $ma->setZeroFlag(true);
            // Write src using pre-computed address
            if ($isByte) {
                if ($isRegister) {
                    $this->write8BitRegister($runtime, $modrm->registerOrMemoryAddress(), $src);
                } else {
                    $this->writeMemory8($runtime, $linearAddr, $src);
                }
            } else {
                if ($isRegister) {
                    $this->writeRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $src, $opSize);
                } else {
                    if ($opSize === 32) {
                        $this->writeMemory32($runtime, $linearAddr, $src);
                    } else {
                        $this->writeMemory16($runtime, $linearAddr, $src);
                    }
                }
            }
        } else {
            $ma->setZeroFlag(false);
            if ($isByte) {
                $ma->writeToLowBit(RegisterType::EAX, $dest & 0xFF);
            } else {
                $ma->writeBySize(RegisterType::EAX, $dest & $mask, $opSize);
            }
        }

        return ExecutionStatus::SUCCESS;
    }

    private function xadd(RuntimeInterface $runtime, EnhanceStreamReader $reader, bool $isByte): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();
        $mask = $opSize === 32 ? 0xFFFFFFFF : (($opSize === 16) ? 0xFFFF : 0xFF);

        // Pre-compute address to avoid consuming displacement twice
        $isRegister = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        $linearAddr = $isRegister ? null : $this->rmLinearAddress($runtime, $reader, $modrm);

        // Read dest using pre-computed address
        if ($isByte) {
            $dest = $isRegister
                ? $this->read8BitRegister($runtime, $modrm->registerOrMemoryAddress())
                : $this->readMemory8($runtime, $linearAddr);
        } else {
            $dest = $isRegister
                ? $this->readRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $opSize)
                : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        }

        $src = $isByte
            ? $this->readRegisterBySize($runtime, $modrm->registerOrOPCode(), 8)
            : $this->readRegisterBySize($runtime, $modrm->registerOrOPCode(), $opSize);

        $sum = $dest + $src;
        $result = $sum & $mask;
        $ma->setCarryFlag($sum > $mask)->updateFlags($result, $opSize);

        // Write result using pre-computed address
        if ($isByte) {
            if ($isRegister) {
                $this->write8BitRegister($runtime, $modrm->registerOrMemoryAddress(), $result);
            } else {
                $this->writeMemory8($runtime, $linearAddr, $result);
            }
        } else {
            if ($isRegister) {
                $this->writeRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $result, $opSize);
            } else {
                if ($opSize === 32) {
                    $this->writeMemory32($runtime, $linearAddr, $result);
                } else {
                    $this->writeMemory16($runtime, $linearAddr, $result);
                }
            }
        }

        // Source register receives original destination value.
        if ($isByte) {
            $this->write8BitRegister($runtime, $modrm->registerOrOPCode(), $dest);
        } else {
            $this->writeRegisterBySize($runtime, $modrm->registerOrOPCode(), $dest, $opSize);
        }

        return ExecutionStatus::SUCCESS;
    }

    private function bswap(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reg = $opcode & 0x7;
        $opSize = $runtime->context()->cpu()->operandSize();
        if ($opSize !== 32) {
            return ExecutionStatus::SUCCESS;
        }

        $val = $runtime->memoryAccessor()->fetch($reg)->asBytesBySize(32);
        $swapped = (($val & 0xFF000000) >> 24)
            | (($val & 0x00FF0000) >> 8)
            | (($val & 0x0000FF00) << 8)
            | (($val & 0x000000FF) << 24);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($reg, $swapped & 0xFFFFFFFF, 32);
        return ExecutionStatus::SUCCESS;
    }

    private function clts(RuntimeInterface $runtime): ExecutionStatus
    {
        $cr0 = $runtime->memoryAccessor()->readControlRegister(0);
        $cr0 &= ~(1 << 3); // clear TS
        $runtime->memoryAccessor()->writeControlRegister(0, $cr0 | 0x22); // keep MP/NE set
        return ExecutionStatus::SUCCESS;
    }

    private function rdtsc(RuntimeInterface $runtime): ExecutionStatus
    {
        // Use host microtime as a monotonic-ish counter.
        $tsc = (int) (microtime(true) * 1_000_000);
        $low = $tsc & 0xFFFFFFFF;
        $high = ($tsc >> 32) & 0xFFFFFFFF;
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize(RegisterType::EAX, $low, 32);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize(RegisterType::EDX, $high, 32);
        return ExecutionStatus::SUCCESS;
    }

    private function shld(RuntimeInterface $runtime, EnhanceStreamReader $reader, bool $imm): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;

        // x86 encoding order: ModR/M -> SIB -> displacement -> immediate
        // Read dest first (consumes displacement), THEN read immediate
        $isRegister = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        $linearAddr = $isRegister ? null : $this->rmLinearAddress($runtime, $reader, $modrm);

        // NOW read immediate (after displacement has been consumed)
        $count = $imm ? ($reader->streamReader()->byte() & 0x1F) : ($runtime->memoryAccessor()->fetch(RegisterType::ECX)->asLowBit() & 0x1F);
        if ($count === 0) {
            return ExecutionStatus::SUCCESS;
        }

        $dest = $isRegister
            ? $this->readRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        $dest &= $mask;
        $src = $this->readRegisterBySize($runtime, $modrm->registerOrOPCode(), $opSize) & $mask;
        $result = (($dest << $count) | ($src >> ($opSize - $count))) & $mask;
        $cf = ($dest >> ($opSize - $count)) & 0x1;
        $of = false;
        if ($count === 1) {
            $msb = ($result >> ($opSize - 1)) & 1;
            $next = ($result >> ($opSize - 2)) & 1;
            $of = ($msb ^ $next) === 1;
        }

        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $ma->setCarryFlag($cf === 1)->updateFlags($result, $opSize);
        $ma->setOverflowFlag($of);

        if ($isRegister) {
            $this->writeRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $result, $opSize);
        } else {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $linearAddr, $result);
            } else {
                $this->writeMemory16($runtime, $linearAddr, $result);
            }
        }
        return ExecutionStatus::SUCCESS;
    }

    private function shrd(RuntimeInterface $runtime, EnhanceStreamReader $reader, bool $imm): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;

        // x86 encoding order: ModR/M -> SIB -> displacement -> immediate
        // Read dest first (consumes displacement), THEN read immediate
        $isRegister = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        $linearAddr = $isRegister ? null : $this->rmLinearAddress($runtime, $reader, $modrm);

        // NOW read immediate (after displacement has been consumed)
        $count = $imm ? ($reader->streamReader()->byte() & 0x1F) : ($runtime->memoryAccessor()->fetch(RegisterType::ECX)->asLowBit() & 0x1F);
        if ($count === 0) {
            return ExecutionStatus::SUCCESS;
        }

        $dest = $isRegister
            ? $this->readRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $opSize)
            : ($opSize === 32 ? $this->readMemory32($runtime, $linearAddr) : $this->readMemory16($runtime, $linearAddr));
        $dest &= $mask;
        $src = $this->readRegisterBySize($runtime, $modrm->registerOrOPCode(), $opSize) & $mask;
        $result = (($dest >> $count) | ($src << ($opSize - $count))) & $mask;
        $cf = ($dest >> ($count - 1)) & 0x1;
        $of = false;
        if ($count === 1) {
            $msbBefore = ($dest >> ($opSize - 1)) & 1;
            $msbAfter = ($result >> ($opSize - 1)) & 1;
            $of = ($msbBefore ^ $msbAfter) === 1;
        }

        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $ma->setCarryFlag($cf === 1)->updateFlags($result, $opSize);
        $ma->setOverflowFlag($of);

        if ($isRegister) {
            $this->writeRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $result, $opSize);
        } else {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $linearAddr, $result);
            } else {
                $this->writeMemory16($runtime, $linearAddr, $result);
            }
        }
        return ExecutionStatus::SUCCESS;
    }


    private function rdmsr(RuntimeInterface $runtime): ExecutionStatus
    {
        if ($runtime->context()->cpu()->cpl() !== 0) {
            throw new FaultException(0x0D, 0, 'RDMSR privilege check failed');
        }
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $ecx = $ma->fetch(RegisterType::ECX)->asBytesBySize(32);
        $value = self::$msr[$ecx] ?? 0;
        if ($ecx === 0x10) { // TSC MSR
            $value = ((int) (microtime(true) * 1_000_000)) & 0xFFFFFFFFFFFFFFFF;
        } elseif ($ecx === 0x1B) { // APIC_BASE
            $value = $runtime->context()->cpu()->apicState()->readMsrApicBase();
        } elseif ($ecx === 0xC0000080) { // EFER
            $value = $runtime->memoryAccessor()->readEfer();
        } elseif (in_array($ecx, [0x174, 0x175, 0x176], true)) { // SYSENTER_CS/ESP/EIP
            $value = self::$msr[$ecx] ?? 0;
        }
        $ma->writeBySize(RegisterType::EAX, $value & 0xFFFFFFFF, 32);
        $ma->writeBySize(RegisterType::EDX, ($value >> 32) & 0xFFFFFFFF, 32);
        return ExecutionStatus::SUCCESS;
    }

    private function wrmsr(RuntimeInterface $runtime): ExecutionStatus
    {
        if ($runtime->context()->cpu()->cpl() !== 0) {
            throw new FaultException(0x0D, 0, 'WRMSR privilege check failed');
        }
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $ecx = $ma->fetch(RegisterType::ECX)->asBytesBySize(32);
        $eax = $ma->fetch(RegisterType::EAX)->asBytesBySize(32);
        $edx = $ma->fetch(RegisterType::EDX)->asBytesBySize(32);
        $value = ($edx << 32) | ($eax & 0xFFFFFFFF);
        self::$msr[$ecx] = $value & 0xFFFFFFFFFFFFFFFF;
        if ($ecx === 0x1B) { // APIC_BASE
            $enable = ($value & (1 << 11)) !== 0;
            $runtime->context()->cpu()->apicState()->setApicBase($value & 0xFFFFF000, $enable);
        } elseif ($ecx === 0xC0000080) { // EFER
            // Allow NXE (bit 11) and PAT/other flags to be stored.
            $runtime->memoryAccessor()->writeEfer($value);
        } elseif (in_array($ecx, [0x174, 0x175, 0x176], true)) { // SYSENTER MSRs
            // no extra side-effects
        }
        return ExecutionStatus::SUCCESS;
    }

    private function sysenter(RuntimeInterface $runtime): ExecutionStatus
    {
        if ($runtime->context()->cpu()->cpl() > 0) {
            throw new FaultException(0x0D, 0, 'SYSENTER CPL check failed');
        }
        $cs = self::$msr[0x174] ?? 0;
        $esp = self::$msr[0x175] ?? null;
        $eip = self::$msr[0x176] ?? null;
        if ($esp === null || $eip === null) {
            throw new FaultException(0x0D, 0, 'SYSENTER MSRs not set');
        }
        $ss = ($cs + 8) & 0xFFFF;
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $ma->write16Bit(RegisterType::CS, $cs & 0xFFFF);
        $ma->write16Bit(RegisterType::SS, $ss);
        $ma->writeBySize(RegisterType::ESP, $esp & 0xFFFFFFFF, 32);
        $runtime->context()->cpu()->setCpl(0);
        $runtime->context()->cpu()->setUserMode(false);
        $target = $this->linearCodeAddress($runtime, $cs, $eip & 0xFFFFFFFF, 32);
        $runtime->memory()->setOffset($target);
        return ExecutionStatus::SUCCESS;
    }

    private function sysexit(RuntimeInterface $runtime): ExecutionStatus
    {
        if ($runtime->context()->cpu()->cpl() !== 0) {
            throw new FaultException(0x0D, 0, 'SYSEXIT CPL check failed');
        }
        $csBase = self::$msr[0x174] ?? 0;
        $cs = ($csBase + 16) & 0xFFFF;
        $ss = ($csBase + 24) & 0xFFFF;
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $eip = $ma->fetch(RegisterType::ECX)->asBytesBySize(32);
        $esp = $ma->fetch(RegisterType::EDX)->asBytesBySize(32);
        $ma->write16Bit(RegisterType::CS, $cs);
        $ma->write16Bit(RegisterType::SS, $ss);
        $ma->writeBySize(RegisterType::ESP, $esp & 0xFFFFFFFF, 32);
        $runtime->context()->cpu()->setCpl(3);
        $runtime->context()->cpu()->setUserMode(true);
        $target = $this->linearCodeAddress($runtime, $cs, $eip & 0xFFFFFFFF, 32);
        $runtime->memory()->setOffset($target);
        return ExecutionStatus::SUCCESS;
    }

    private function cmpxchg8b(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        if (ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException('CMPXCHG8B requires memory operand');
        }
        if (($modrm->registerOrOPCode() & 0x7) !== 0x1) {
            // Other /r encodings unused here.
            return ExecutionStatus::SUCCESS;
        }

        $address = $this->rmLinearAddress($runtime, $reader, $modrm);
        $low = $this->readMemory32($runtime, $address);
        $high = $this->readMemory32($runtime, $address + 4);

        $eax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize(32);
        $edx = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asBytesBySize(32);

        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        if ($low === $eax && $high === $edx) {
            $ma->setZeroFlag(true);
            $ebx = $ma->fetch(RegisterType::EBX)->asBytesBySize(32);
            $ecx = $ma->fetch(RegisterType::ECX)->asBytesBySize(32);
            $this->writeMemory32($runtime, $address, $ebx);
            $this->writeMemory32($runtime, $address + 4, $ecx);
        } else {
            $ma->setZeroFlag(false);
            $ma->writeBySize(RegisterType::EAX, $low, 32);
            $ma->writeBySize(RegisterType::EDX, $high, 32);
        }
        // Other flags undefined; leave as-is.
        return ExecutionStatus::SUCCESS;
    }

    private function movzx(RuntimeInterface $runtime, EnhanceStreamReader $reader, bool $isByte): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();
        $value = $isByte
            ? $this->readRm8($runtime, $reader, $modrm)
            : $this->readRm16($runtime, $reader, $modrm);

        $this->writeRegisterBySize($runtime, $modrm->registerOrOPCode(), $value, $opSize);
        return ExecutionStatus::SUCCESS;
    }

    private function movsx(RuntimeInterface $runtime, EnhanceStreamReader $reader, bool $isByte): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();
        $value = $isByte
            ? $this->readRm8($runtime, $reader, $modrm)
            : $this->readRm16($runtime, $reader, $modrm);

        // Sign extend the value
        if ($isByte) {
            // Sign extend 8-bit to 16/32-bit
            if ($value & 0x80) {
                $value = $opSize === 32 ? ($value | 0xFFFFFF00) : ($value | 0xFF00);
            }
        } else {
            // Sign extend 16-bit to 32-bit
            if ($opSize === 32 && ($value & 0x8000)) {
                $value = $value | 0xFFFF0000;
            }
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $this->writeRegisterBySize($runtime, $modrm->registerOrOPCode(), $value & $mask, $opSize);
        return ExecutionStatus::SUCCESS;
    }

    private function imulRegRm(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();
        $src = $this->readRm($runtime, $reader, $modrm, $opSize);
        $dst = $this->readRegisterBySize($runtime, $modrm->registerOrOPCode(), $opSize);

        $sSrc = $this->signExtend($src, $opSize);
        $sDst = $this->signExtend($dst, $opSize);
        $product = $sSrc * $sDst;
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = $product & $mask;

        $this->writeRegisterBySize($runtime, $modrm->registerOrOPCode(), $result, $opSize);
        $min = -(1 << ($opSize - 1));
        $max = (1 << ($opSize - 1)) - 1;
        $overflow = $product < $min || $product > $max;
        $runtime->memoryAccessor()->setCarryFlag($overflow)->setOverflowFlag($overflow);
        return ExecutionStatus::SUCCESS;
    }

    private function bsf(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();
        $src = $this->readRm($runtime, $reader, $modrm, $opSize);
        if ($src === 0) {
            $runtime->memoryAccessor()->setZeroFlag(true);
            return ExecutionStatus::SUCCESS;
        }
        $runtime->memoryAccessor()->setZeroFlag(false);
        $index = 0;
        while ((($src >> $index) & 1) === 0 && $index < $opSize) {
            $index++;
        }
        $this->writeRegisterBySize($runtime, $modrm->registerOrOPCode(), $index, $opSize);
        return ExecutionStatus::SUCCESS;
    }

    private function bsr(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();
        $src = $this->readRm($runtime, $reader, $modrm, $opSize);
        if ($src === 0) {
            $runtime->memoryAccessor()->setZeroFlag(true);
            return ExecutionStatus::SUCCESS;
        }
        $runtime->memoryAccessor()->setZeroFlag(false);
        $index = $opSize - 1;
        while ($index >= 0 && ((($src >> $index) & 1) === 0)) {
            $index--;
        }
        $this->writeRegisterBySize($runtime, $modrm->registerOrOPCode(), max(0, $index), $opSize);
        return ExecutionStatus::SUCCESS;
    }

    private function cmovcc(RuntimeInterface $runtime, EnhanceStreamReader $reader, int $opcode): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();
        $cc = $opcode & 0x0F;

        if (!$this->conditionMet($runtime, $cc)) {
            // still consume addressing
            if (ModType::from($modrm->mode()) !== ModType::REGISTER_TO_REGISTER) {
                $this->rmLinearAddress($runtime, $reader, $modrm);
            }
            return ExecutionStatus::SUCCESS;
        }

        $value = $this->readRm($runtime, $reader, $modrm, $opSize);
        $this->writeRegisterBySize($runtime, $modrm->registerOrOPCode(), $value, $opSize);
        return ExecutionStatus::SUCCESS;
    }

    private function setcc(RuntimeInterface $runtime, EnhanceStreamReader $reader, int $opcode): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        $cc = $opcode & 0x0F;
        $val = $this->conditionMet($runtime, $cc) ? 1 : 0;

        if (ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER) {
            $this->write8BitRegister($runtime, $modrm->registerOrMemoryAddress(), $val);
        } else {
            $addr = $this->rmLinearAddress($runtime, $reader, $modrm);
            $this->writeMemory8($runtime, $addr, $val);
        }
        return ExecutionStatus::SUCCESS;
    }

    private function bitOp(RuntimeInterface $runtime, EnhanceStreamReader $reader, ?string $op, bool $immediate, ?int $forcedBit = null): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();
        $maskBits = $opSize === 32 ? 0x1F : 0x0F;

        $isReg = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;

        // x86 encoding order: ModR/M -> SIB -> displacement -> immediate
        // Consume displacement FIRST (for memory operand), THEN read immediate
        $baseAddr = $isReg ? null : $this->rmLinearAddress($runtime, $reader, $modrm);

        // NOW determine op and bitIndex (immediate read after displacement)
        if ($immediate) {
            $imm = $reader->streamReader()->byte() & 0xFF;
            $subop = $op ?? match ($modrm->registerOrOPCode() & 0x7) {
                0b100 => 'bt',
                0b101 => 'bts',
                0b110 => 'btr',
                0b111 => 'btc',
                default => null,
            };
            $bitIndex = $imm;
            $op = $subop;
        } else {
            $bitIndex = $this->readRegisterBySize($runtime, $modrm->registerOrOPCode(), $opSize);
        }
        if ($op === null) {
            return ExecutionStatus::SUCCESS;
        }

        $bitWithin = $bitIndex & $maskBits;

        if ($isReg) {
            $dest = $this->readRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $opSize);
            $bit = ($dest >> $bitWithin) & 0x1;
            $runtime->memoryAccessor()->setCarryFlag($bit === 1);
            $newVal = match ($op) {
                'bt' => $dest,
                'bts' => $dest | (1 << $bitWithin),
                'btr' => $dest & ~(1 << $bitWithin),
                'btc' => $dest ^ (1 << $bitWithin),
                default => $dest,
            };
            if ($op !== 'bt') {
                $this->writeRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $newVal, $opSize);
            }
            return ExecutionStatus::SUCCESS;
        }

        $elemSizeBytes = $opSize === 32 ? 4 : 2;
        $elemIndex = intdiv($bitIndex, $opSize);
        $targetAddr = $baseAddr + ($elemIndex * $elemSizeBytes);
        $value = $opSize === 32 ? $this->readMemory32($runtime, $targetAddr) : $this->readMemory16($runtime, $targetAddr);
        $bitWithin = $bitIndex % $opSize;
        $bit = ($value >> $bitWithin) & 0x1;
        $runtime->memoryAccessor()->setCarryFlag($bit === 1);
        $newVal = match ($op) {
            'bt' => $value,
            'bts' => $value | (1 << $bitWithin),
            'btr' => $value & ~(1 << $bitWithin),
            'btc' => $value ^ (1 << $bitWithin),
            default => $value,
        };
        if ($op !== 'bt') {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $targetAddr, $newVal);
            } else {
                $this->writeMemory16($runtime, $targetAddr, $newVal);
            }
        }
        return ExecutionStatus::SUCCESS;
    }

    private function jccNear(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $cc = $opcode & 0x0F;
        $opSize = $runtime->context()->cpu()->operandSize();
        $disp = $opSize === 32
            ? $runtime->memory()->dword()
            : $runtime->memory()->short();

        // Sign-extend displacement
        if ($opSize === 32) {
            $disp = (int) (pack('V', $disp) === false ? $disp : unpack('l', pack('V', $disp))[1]);
        } else {
            // Sign-extend 16-bit to signed integer
            if ($disp > 0x7FFF) {
                $disp = $disp - 0x10000;
            }
        }

        if ($this->conditionMet($runtime, $cc)) {
            $current = $runtime->memory()->offset();
            $runtime->memory()->setOffset(($current + $disp) & 0xFFFFFFFF);
        }
        return ExecutionStatus::SUCCESS;
    }

    private function nopModrm(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        if (ModType::from($modrm->mode()) !== ModType::REGISTER_TO_REGISTER) {
            $this->rmLinearAddress($runtime, $reader, $modrm); // consume displacement/SIB
        }
        return ExecutionStatus::SUCCESS;
    }

    private function conditionMet(RuntimeInterface $runtime, int $cc): bool
    {
        $ma = $runtime->memoryAccessor();
        $zf = $ma->shouldZeroFlag();
        $cf = $ma->shouldCarryFlag();
        $sf = $ma->shouldSignFlag();
        $of = $ma->shouldOverflowFlag();
        $pf = $ma->shouldParityFlag();

        return match ($cc) {
            0x0 => $of,
            0x1 => !$of,
            0x2 => $cf,
            0x3 => !$cf,
            0x4 => $zf,
            0x5 => !$zf,
            0x6 => $cf || $zf,
            0x7 => !$cf && !$zf,
            0x8 => $sf,
            0x9 => !$sf,
            0xA => $pf,
            0xB => !$pf,
            0xC => $sf !== $of,
            0xD => $sf === $of,
            0xE => $zf || ($sf !== $of),
            0xF => !$zf && ($sf === $of),
            default => false,
        };
    }

    private function fxsaveFxrstor(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $modrmByte = $reader->streamReader()->byte();
        $modrm = $reader->modRegRM($modrmByte);
        $mod = ModType::from($modrm->mode());
        // MFENCE/LFENCE/SFENCE encoded as 0F AE /6 with mod == 11 (reg=6)
        if ($mod === ModType::REGISTER_TO_REGISTER && (($modrm->registerOrOPCode() & 0x7) === 0x6)) {
            return ExecutionStatus::SUCCESS;
        }
        if ($mod === ModType::REGISTER_TO_REGISTER) {
            return ExecutionStatus::SUCCESS;
        }

        $reg = $modrm->registerOrOPCode() & 0x7;
        $address = $this->rmLinearAddress($runtime, $reader, $modrm);
        $size = 512; // fxsave/fxrstor region

        if ($reg === 0) { // fxsave
            // FCW/FSW/FTW/Opcode/IP/DP placeholders
            $this->writeMemory16($runtime, $address + 0, 0x037F);
            $this->writeMemory16($runtime, $address + 2, 0);
            $this->writeMemory16($runtime, $address + 4, 0);
            $this->writeMemory16($runtime, $address + 6, 0);
            $this->writeMemory32($runtime, $address + 8, 0);
            $this->writeMemory32($runtime, $address + 12, 0);
            $this->writeMemory32($runtime, $address + 16, 0);
            $this->writeMemory32($runtime, $address + 20, 0);
            // MXCSR and mask
            $this->writeMemory32($runtime, $address + 24, $this->mxcsr);
            $this->writeMemory32($runtime, $address + 28, 0xFFFF);
            // Save first 8 XMM registers
            $this->initXmm();
            $xmm = $this->xmm;
            $base = $address + 160;
            for ($i = 0; $i < 8; $i++) {
                $regVals = $xmm[$i] ?? [0, 0, 0, 0];
                $this->writeMemory32($runtime, $base + ($i * 16) + 0, $regVals[0]);
                $this->writeMemory32($runtime, $base + ($i * 16) + 4, $regVals[1]);
                $this->writeMemory32($runtime, $base + ($i * 16) + 8, $regVals[2]);
                $this->writeMemory32($runtime, $base + ($i * 16) + 12, $regVals[3]);
            }
            // Zero the rest (roughly)
            for ($i = 48; $i < 160; $i++) {
                $this->writeMemory8($runtime, ($address + $i) & 0xFFFFFFFF, 0);
            }
        } elseif ($reg === 1) { // fxrstor
            $this->translateLinear($runtime, $address, false);
            $this->mxcsr = $this->readMemory32($runtime, $address + 24);
            $this->initXmm();
            $base = $address + 160;
            for ($i = 0; $i < 8; $i++) {
                $d0 = $this->readMemory32($runtime, $base + ($i * 16));
                $d1 = $this->readMemory32($runtime, $base + ($i * 16) + 4);
                $d2 = $this->readMemory32($runtime, $base + ($i * 16) + 8);
                $d3 = $this->readMemory32($runtime, $base + ($i * 16) + 12);
                $this->writeXmm($i, [$d0, $d1, $d2, $d3]);
            }
        } elseif ($reg === 7) { // clflush m8
            // Consume address and treat as no-op
            $this->writeMemory8($runtime, $address & 0xFFFFFFFF, $this->readMemory8($runtime, $address));
        }

        return ExecutionStatus::SUCCESS;
    }

    private function pushFsGs(RuntimeInterface $runtime, RegisterType $seg): ExecutionStatus
    {
        $opSize = $runtime->context()->cpu()->operandSize();
        $val = $runtime->memoryAccessor()->fetch($seg)->asByte() & 0xFFFF;
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $val, $opSize);
        return ExecutionStatus::SUCCESS;
    }

    private function popFsGs(RuntimeInterface $runtime, RegisterType $seg): ExecutionStatus
    {
        $opSize = $runtime->context()->cpu()->operandSize();
        $val = $runtime->memoryAccessor()->enableUpdateFlags(false)->pop(RegisterType::ESP, $opSize)->asBytesBySize($opSize) & 0xFFFF;
        $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit($seg, $val);
        return ExecutionStatus::SUCCESS;
    }

    private function sseNoOp(RuntimeInterface $runtime, EnhanceStreamReader $reader, bool $hasImmediate, ?int $opcode = null): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        $isRegReg = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        $regIndex = $modrm->registerOrOPCode() & 0x7;
        $this->initXmm();

        $loadOpcodes128 = [0x10, 0x28, 0x6F];
        $storeOpcodes128 = [0x11, 0x29, 0x7F];
        $loadOpcodes64 = [0x6E];
        $storeOpcodes64 = [0x7E];

        if (!$isRegReg) {
            $addr = $this->rmLinearAddress($runtime, $reader, $modrm);
            if ($opcode !== null && in_array($opcode, $loadOpcodes128, true)) {
                $this->writeXmm($regIndex, [
                    $this->readMemory32($runtime, $addr),
                    $this->readMemory32($runtime, $addr + 4),
                    $this->readMemory32($runtime, $addr + 8),
                    $this->readMemory32($runtime, $addr + 12),
                ]);
            } elseif ($opcode !== null && in_array($opcode, $storeOpcodes128, true)) {
                [$d0, $d1, $d2, $d3] = $this->readXmm($regIndex);
                $this->writeMemory32($runtime, $addr, $d0);
                $this->writeMemory32($runtime, $addr + 4, $d1);
                $this->writeMemory32($runtime, $addr + 8, $d2);
                $this->writeMemory32($runtime, $addr + 12, $d3);
            } elseif ($opcode !== null && in_array($opcode, $loadOpcodes64, true)) {
                $this->writeXmm($regIndex, [
                    $this->readMemory32($runtime, $addr),
                    $this->readMemory32($runtime, $addr + 4),
                    0,
                    0,
                ]);
            } elseif ($opcode !== null && in_array($opcode, $storeOpcodes64, true)) {
                [$d0, $d1] = $this->readXmm($regIndex);
                $this->writeMemory32($runtime, $addr, $d0);
                $this->writeMemory32($runtime, $addr + 4, $d1);
            }
        } else {
            // reg-reg moves
            if ($opcode !== null && (in_array($opcode, $loadOpcodes128, true) || in_array($opcode, $storeOpcodes128, true))) {
                $this->writeXmm($modrm->registerOrMemoryAddress() & 0x7, $this->readXmm($regIndex));
            } elseif ($opcode !== null && (in_array($opcode, $loadOpcodes64, true) || in_array($opcode, $storeOpcodes64, true))) {
                $destIdx = $modrm->registerOrMemoryAddress() & 0x7;
                $src = $this->readXmm($regIndex);
                $this->writeXmm($destIdx, [$src[0], $src[1], 0, 0]);
            }
        }

        if ($hasImmediate) {
            $reader->streamReader()->byte();
        }
        return ExecutionStatus::SUCCESS;
    }

    private function initXmm(): void
    {
        if (!empty($this->xmm)) {
            return;
        }
        $this->xmm = array_fill(0, 8, [0, 0, 0, 0]);
    }

    private function readXmm(int $index): array
    {
        return $this->xmm[$index] ?? [0, 0, 0, 0];
    }

    private function writeXmm(int $index, array $data): void
    {
        $padded = array_pad($data, 4, 0);
        $this->xmm[$index] = [
            $padded[0] & 0xFFFFFFFF,
            $padded[1] & 0xFFFFFFFF,
            $padded[2] & 0xFFFFFFFF,
            $padded[3] & 0xFFFFFFFF,
        ];
    }

    private function group6(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();

        return match ($modrm->registerOrOPCode()) {
            0b000 => $this->sgdt($runtime, $reader, $modrm),
            0b001 => $this->sidt($runtime, $reader, $modrm),
            0b010 => $this->lgdt($runtime, $reader, $modrm),
            0b011 => $this->lidt($runtime, $reader, $modrm),
            0b101 => $this->lmsw($runtime, $reader, $modrm),
            0b111 => $this->invlpg($runtime, $reader, $modrm),
            default => ExecutionStatus::SUCCESS,
        };
    }

    private function group0(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();

        return match ($modrm->registerOrOPCode()) {
            0b000 => $this->sldt($runtime, $reader, $modrm),
            0b001 => $this->str($runtime, $reader, $modrm),
            0b010 => $this->lldt($runtime, $reader, $modrm),
            0b011 => $this->ltr($runtime, $reader, $modrm),
            default => ExecutionStatus::SUCCESS,
        };
    }

    private function lgdt(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $address = $this->rmLinearAddress($runtime, $reader, $modrm);
        $limit = $this->readMemory16($runtime, $address);
        $base = $this->readMemory16($runtime, $address + 2);
        $base |= ($this->readMemory16($runtime, $address + 4) << 16) & 0xFFFF0000;
        $runtime->context()->cpu()->setGdtr($base, $limit);

        // Debug: dump GDT entries
        $runtime->option()->logger()->debug(sprintf(
            'LGDT: address=0x%05X base=0x%08X limit=0x%04X',
            $address,
            $base,
            $limit
        ));
        // Dump the first 5 GDT entries
        for ($i = 0; $i < 5 && ($i * 8) <= $limit; $i++) {
            $descAddr = $base + ($i * 8);
            $bytes = [];
            for ($j = 0; $j < 8; $j++) {
                $bytes[] = sprintf('%02X', $this->readMemory8($runtime, $descAddr + $j));
            }
            $runtime->option()->logger()->debug(sprintf(
                'GDT[%d] at 0x%08X: %s',
                $i,
                $descAddr,
                implode(' ', $bytes)
            ));
        }

        return ExecutionStatus::SUCCESS;
    }

    private function lidt(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $address = $this->rmLinearAddress($runtime, $reader, $modrm);
        $limit = $this->readMemory16($runtime, $address);
        $base = $this->readMemory16($runtime, $address + 2);
        $base |= ($this->readMemory16($runtime, $address + 4) << 16) & 0xFFFF0000;
        $runtime->context()->cpu()->setIdtr($base, $limit);
        return ExecutionStatus::SUCCESS;
    }

    private function sgdt(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $address = $this->rmLinearAddress($runtime, $reader, $modrm);
        $gdtr = $runtime->context()->cpu()->gdtr();
        $this->writeMemory16($runtime, $address, $gdtr['limit'] ?? 0);
        $base = $gdtr['base'] ?? 0;
        $this->writeMemory16($runtime, $address + 2, $base & 0xFFFF);
        $this->writeMemory16($runtime, $address + 4, ($base >> 16) & 0xFFFF);
        return ExecutionStatus::SUCCESS;
    }

    private function sidt(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $address = $this->rmLinearAddress($runtime, $reader, $modrm);
        $idtr = $runtime->context()->cpu()->idtr();
        $this->writeMemory16($runtime, $address, $idtr['limit'] ?? 0);
        $base = $idtr['base'] ?? 0;
        $this->writeMemory16($runtime, $address + 2, $base & 0xFFFF);
        $this->writeMemory16($runtime, $address + 4, ($base >> 16) & 0xFFFF);
        return ExecutionStatus::SUCCESS;
    }

    private function lmsw(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $value = $this->readRm16($runtime, $reader, $modrm);
        // LMSW only affects lower 4 bits of CR0: PE, MP, EM, TS
        $cr0 = $runtime->memoryAccessor()->readControlRegister(0);
        $cr0 = ($cr0 & 0xFFFFFFF0) | ($value & 0xF);
        $runtime->memoryAccessor()->writeControlRegister(0, $cr0);
        $runtime->context()->cpu()->setProtectedMode((bool) ($cr0 & 0x1));
        $runtime->context()->cpu()->setPagingEnabled((bool) ($cr0 & 0x80000000));
        return ExecutionStatus::SUCCESS;
    }

    private function invlpg(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        // Just consume the memory operand; no TLB modeled.
        $this->rmLinearAddress($runtime, $reader, $modrm);
        return ExecutionStatus::SUCCESS;
    }

    private function ltr(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $selector = $this->readRm16($runtime, $reader, $modrm);
        if (!$runtime->context()->cpu()->isProtectedMode()) {
            return ExecutionStatus::SUCCESS;
        }

        $descriptor = $this->readSegmentDescriptor($runtime, $selector);
        if ($descriptor === null) {
            throw new FaultException(0x0D, $selector, sprintf('Invalid TSS selector 0x%04X', $selector));
        }
        if (!$descriptor['present']) {
            throw new FaultException(0x0B, $selector, sprintf('TSS selector 0x%04X not present', $selector));
        }

        $type = $descriptor['type'] ?? 0;
        $validTypes = [0x1, 0x3, 0x9, 0xB]; // 16/32-bit TSS available/busy
        if (!in_array($type, $validTypes, true)) {
            throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not a TSS', $selector));
        }

        $runtime->context()->cpu()->setTaskRegister($selector, $descriptor['base'], $descriptor['limit']);

        // Mark TSS busy when loading if descriptor is "available".
        if ($type === 0x1 || $type === 0x9) {
            $gdtr = $runtime->context()->cpu()->gdtr();
            $base = $gdtr['base'] ?? 0;
            $index = ($selector >> 3) & 0x1FFF;
            $descAddr = $base + ($index * 8);
            $accessAddr = $descAddr + 5;

            $access = $this->readMemory8($runtime, $accessAddr);
            $access |= 0x02; // busy bit

            $phys = $this->translateLinear($runtime, $accessAddr, true);
            $runtime->memoryAccessor()->allocate($phys, safe: false);
            $runtime->memoryAccessor()->writeBySize($phys, $access & 0xFF, 8);
        }
        return ExecutionStatus::SUCCESS;
    }

    private function lldt(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $selector = $this->readRm16($runtime, $reader, $modrm);

        if (!$runtime->context()->cpu()->isProtectedMode()) {
            return ExecutionStatus::SUCCESS;
        }

        if (($selector & 0x4) !== 0) {
            throw new FaultException(0x0D, $selector, sprintf('LLDT selector 0x%04X must reference GDT (TI=0)', $selector));
        }

        if (($selector & 0xFFFC) === 0) {
            // Null selector disables LDTR.
            $runtime->context()->cpu()->setLdtr(0, 0, 0);
            return ExecutionStatus::SUCCESS;
        }

        $descriptor = $this->readSegmentDescriptor($runtime, $selector);
        if ($descriptor === null) {
            throw new FaultException(0x0D, $selector, sprintf('Invalid LDT selector 0x%04X', $selector));
        }
        if (!$descriptor['present']) {
            throw new FaultException(0x0B, $selector, sprintf('LDT selector 0x%04X not present', $selector));
        }
        if (($descriptor['type'] ?? 0) !== 0x2) {
            throw new FaultException(0x0D, $selector, sprintf('Selector 0x%04X is not an LDT descriptor', $selector));
        }

        $runtime->context()->cpu()->setLdtr($selector, $descriptor['base'], $descriptor['limit']);
        return ExecutionStatus::SUCCESS;
    }

    private function sldt(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $selector = $runtime->context()->cpu()->ldtr()['selector'] ?? 0;
        $this->writeRm16($runtime, $reader, $modrm, $selector);
        return ExecutionStatus::SUCCESS;
    }

    private function str(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $selector = $runtime->context()->cpu()->taskRegister()['selector'] ?? 0;
        $this->writeRm16($runtime, $reader, $modrm, $selector);
        return ExecutionStatus::SUCCESS;
    }
}
