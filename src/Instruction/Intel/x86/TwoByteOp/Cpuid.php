<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * CPUID (0x0F 0xA2)
 * Return processor identification and feature information.
 */
class Cpuid implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0xA2]]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $ma = $runtime->memoryAccessor();
        $leaf = $ma->fetch(RegisterType::EAX)->asBytesBySize(32);

        switch ($leaf) {
            case 0x0:
                // Max leaf 7, vendor "GenuineIntel"
                $this->writeRegisterBySize($runtime, RegisterType::EAX, 0x00000007, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EBX, 0x756E6547, 32); // "Genu"
                $this->writeRegisterBySize($runtime, RegisterType::EDX, 0x49656E69, 32); // "ineI"
                $this->writeRegisterBySize($runtime, RegisterType::ECX, 0x6C65746E, 32); // "ntel"
                break;

            case 0x1:
                // Basic feature bits: keep x86_64 baseline features present.
                $this->writeRegisterBySize($runtime, RegisterType::EAX, 0x00000601, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EBX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::ECX, 0, 32);
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
                    | (1 << 12) // MTRR
                    | (1 << 16) // PAT
                    | (1 << 19) // CLFSH
                    | (1 << 13) // PGE
                    | (1 << 15) // CMOV
                    | (1 << 17) // PSE-36
                    | (1 << 24) // FXSR
                    | (1 << 25) // SSE
                    | (1 << 26); // SSE2
                $this->writeRegisterBySize($runtime, RegisterType::EDX, $features, 32);
                break;

            case 0x2:
                // Basic cache/TLB descriptor leaf (minimal stub)
                $this->writeRegisterBySize($runtime, RegisterType::EAX, 0x04020101, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EBX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::ECX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EDX, 0, 32);
                break;

            case 0x4:
                // Deterministic cache parameters: return no caches
                $this->writeRegisterBySize($runtime, RegisterType::EAX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EBX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::ECX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EDX, 0, 32);
                break;

            case 0x7:
                // Structured extended feature flags (subleaf 0)
                $this->writeRegisterBySize($runtime, RegisterType::EAX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EBX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::ECX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EDX, 0, 32);
                break;

            case 0x80000000:
                $this->writeRegisterBySize($runtime, RegisterType::EAX, 0x80000008, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EBX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::ECX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EDX, 0, 32);
                break;

            case 0x80000001:
                $this->writeRegisterBySize($runtime, RegisterType::EAX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EBX, 0, 32);
                // LAHF/SAHF in long mode
                $this->writeRegisterBySize($runtime, RegisterType::ECX, (1 << 0), 32);
                // Extended feature bits for x86_64 baseline.
                $extFeatures = (1 << 11) // SYSCALL/SYSRET
                    | (1 << 16) // PAT
                    | (1 << 20) // NX
                    | (1 << 29); // LM (long mode)
                $this->writeRegisterBySize($runtime, RegisterType::EDX, $extFeatures, 32);
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
                $this->writeRegisterBySize($runtime, RegisterType::EAX, $vals[0] ?? 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EBX, $vals[1] ?? 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::ECX, $vals[2] ?? 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EDX, $vals[3] ?? 0, 32);
                break;

            case 0x80000008:
                // Physical address bits 36, linear address bits 48, no extra cores
                $this->writeRegisterBySize($runtime, RegisterType::EAX, 0x00003024, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EBX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::ECX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EDX, 0, 32);
                break;

            default:
                $this->writeRegisterBySize($runtime, RegisterType::EAX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EBX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::ECX, 0, 32);
                $this->writeRegisterBySize($runtime, RegisterType::EDX, 0, 32);
                break;
        }

        return ExecutionStatus::SUCCESS;
    }
}
