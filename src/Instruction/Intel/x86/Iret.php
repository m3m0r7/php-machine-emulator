<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Iret implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xCF]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $cpu = $runtime->context()->cpu();
        $opSize = $runtime->context()->cpu()->operandSize();
        $ma = $runtime->memoryAccessor();

        // IA-32e 64-bit mode: IRETQ stack frame uses 64-bit slots.
        // Pop RIP, CS, RFLAGS, RSP, SS as 64-bit values.
        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $rip = $ma->pop(RegisterType::ESP, 64)->asBytesBySize(64);
            $csQ = $ma->pop(RegisterType::ESP, 64)->asBytesBySize(64);
            $flags = $ma->pop(RegisterType::ESP, 64)->asBytesBySize(64);
            $newRsp = $ma->pop(RegisterType::ESP, 64)->asBytesBySize(64);
            $newSsQ = $ma->pop(RegisterType::ESP, 64)->asBytesBySize(64);

            $ma->write16Bit(RegisterType::SS, $newSsQ & 0xFFFF);
            $ma->writeBySize(RegisterType::ESP, $newRsp, 64);

            $newCpl = $csQ & 0x3;
            $this->writeCodeSegment($runtime, $csQ & 0xFFFF, $newCpl);

            if ($runtime->option()->shouldChangeOffset()) {
                $runtime->memory()->setOffset($rip);
            }

            // Restore flags directly from the popped value
            $ma->setCarryFlag(($flags & 0x1) !== 0);
            $ma->setParityFlag(($flags & (1 << 2)) !== 0);
            $ma->setAuxiliaryCarryFlag(($flags & (1 << 4)) !== 0);
            $ma->setZeroFlag(($flags & (1 << 6)) !== 0);
            $ma->setSignFlag(($flags & (1 << 7)) !== 0);
            $ma->setOverflowFlag(($flags & (1 << 11)) !== 0);
            $ma->setDirectionFlag(($flags & (1 << 10)) !== 0);
            $ma->setInterruptFlag(($flags & (1 << 9)) !== 0);
            // IOPL and NT bits
            $cpu->setIopl(($flags >> 12) & 0x3);
            $cpu->setNt(($flags & (1 << 14)) !== 0);

            return ExecutionStatus::SUCCESS;
        }

        // Real-mode BIOS trampolines sometimes return via IRET even when
        // chained through a far CALL without a preceding PUSHF.
        // In that case, the stack does not contain a flags word for this IRET.
        // Detect it heuristically and fall back to a far return (RETF).
        if (!$cpu->isProtectedMode()) {
            // Keep this heuristic scoped to BIOS ROM code paths; test programs and
            // normal software should follow the architectural IRET stack layout.
            $currentCs = $ma->fetch(RegisterType::CS)->asByte() & 0xFFFF;
            if ($currentCs === 0xF000) {
                $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize($opSize);
                $bytesIp = intdiv($opSize, 8);
                $flagsSp = ($sp + $bytesIp + 2) & 0xFFFF; // +CS(2)
                $flagsLinear = $this->segmentOffsetAddress($runtime, RegisterType::SS, $flagsSp);
                $flagsCandidate = $this->readMemory16($runtime, $flagsLinear);
                if (($flagsCandidate & 0x2) === 0) {
                    // Treat as RETF: pop IP and CS only, leave flags on stack.
                    $ip = $ma->pop(RegisterType::ESP, $opSize)->asBytesBySize($opSize);
                    $cs = $ma->pop(RegisterType::ESP, 16)->asBytesBySize(16);
                    $this->writeCodeSegment($runtime, $cs);
                    if ($runtime->option()->shouldChangeOffset()) {
                        $linear = $this->linearCodeAddress($runtime, $cs & 0xFFFF, $ip, $opSize);
                        $runtime->memory()->setOffset($linear);
                    }
                    return ExecutionStatus::SUCCESS;
                }
            }
        }

        $ip = $ma->pop(RegisterType::ESP, $opSize)->asBytesBySize($opSize);
        // CS selectors are always 16-bit, even when operand size is 32-bit.
        $cs = $ma->pop(RegisterType::ESP, 16)->asBytesBySize(16);
        $flags = $ma->pop(RegisterType::ESP, $opSize)->asBytesBySize($opSize);

        if ($cpu->isProtectedMode() && (($flags & (1 << 14)) !== 0)) {
            // Task switch via IRET when NT set: use backlink from current TSS.
            $tr = $runtime->context()->cpu()->taskRegister();
            $tssSelector = $tr['selector'] ?? 0;
            if ($tssSelector !== 0) {
                $backlink = $this->readMemory16($runtime, $tr['base']);
                $this->taskSwitch($runtime, $backlink, setBusy: false, gateSelector: null, isJump: true);
                return ExecutionStatus::SUCCESS;
            }
        }

        $descriptor = null;
        $nextCpl = null;
        if ($cpu->isProtectedMode()) {
            $descriptor = $this->resolveCodeDescriptor($runtime, $cs);
            $nextCpl = $this->computeCplForTransfer($runtime, $cs, $descriptor);
        }

        $newCpl = $cs & 0x3;
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $returningToOuter = $cpu->isProtectedMode()
            && ($newCpl > $cpu->cpl());

        if ($returningToOuter) {
            $newEsp = $ma->pop(RegisterType::ESP, $opSize)->asBytesBySize($opSize);
            $newSs = $ma->pop(RegisterType::ESP, 16)->asBytesBySize(16);
            $ma->write16Bit(RegisterType::SS, $newSs & 0xFFFF);
            $ma->writeBySize(RegisterType::ESP, $newEsp & $mask, $opSize);
        }

        $this->writeCodeSegment($runtime, $cs, $nextCpl, $descriptor);
        if ($runtime->option()->shouldChangeOffset()) {
            // Calculate linear address considering CS segment
            $linear = $this->linearCodeAddress($runtime, $cs & 0xFFFF, $ip, $opSize);
            $runtime->option()->logger()->debug(sprintf('IRET: CS=0x%04X IP=0x%04X linear=0x%05X flags=0x%04X', $cs, $ip, $linear, $flags));
            $runtime->memory()->setOffset($linear);
        }

        // Restore flags directly from the popped value
        $ma->setCarryFlag(($flags & 0x1) !== 0);
        $ma->setParityFlag(($flags & (1 << 2)) !== 0);
        $ma->setAuxiliaryCarryFlag(($flags & (1 << 4)) !== 0);
        $ma->setZeroFlag(($flags & (1 << 6)) !== 0);
        $ma->setSignFlag(($flags & (1 << 7)) !== 0);
        $ma->setOverflowFlag(($flags & (1 << 11)) !== 0);
        $ma->setDirectionFlag(($flags & (1 << 10)) !== 0);
        $ma->setInterruptFlag(($flags & (1 << 9)) !== 0);
        // IOPL and NT bits
        $runtime->context()->cpu()->setIopl(($flags >> 12) & 0x3);
        $runtime->context()->cpu()->setNt(($flags & (1 << 14)) !== 0);

        return ExecutionStatus::SUCCESS;
    }
}
