<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Iret implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xCF];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $opSize = $runtime->context()->cpu()->operandSize();
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);

        $ip = $ma->pop(RegisterType::ESP, $opSize)->asBytesBySize($opSize);
        $cs = $ma->pop(RegisterType::ESP, $opSize)->asBytesBySize($opSize);
        $flags = $ma->pop(RegisterType::ESP, $opSize)->asBytesBySize($opSize);

        if ($runtime->context()->cpu()->isProtectedMode() && (($flags & (1 << 14)) !== 0)) {
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
        if ($runtime->context()->cpu()->isProtectedMode()) {
            $descriptor = $this->resolveCodeDescriptor($runtime, $cs);
            $nextCpl = $this->computeCplForTransfer($runtime, $cs, $descriptor);
        }

        $newCpl = $cs & 0x3;
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $returningToOuter = $runtime->context()->cpu()->isProtectedMode()
            && ($newCpl > $runtime->context()->cpu()->cpl());

        if ($returningToOuter) {
            $newEsp = $ma->pop(RegisterType::ESP, $opSize)->asBytesBySize($opSize);
            $newSs = $ma->pop(RegisterType::ESP, $opSize)->asBytesBySize($opSize);
            $ma->write16Bit(RegisterType::SS, $newSs & 0xFFFF);
            $ma->writeBySize(RegisterType::ESP, $newEsp & $mask, $opSize);
        }

        $this->writeCodeSegment($runtime, $cs, $nextCpl, $descriptor);
        if ($runtime->option()->shouldChangeOffset()) {
            if (!$runtime->context()->cpu()->isProtectedMode()) {
                $linear = $ip & $mask;
            } else {
                $linear = $this->linearCodeAddress($runtime, $cs & 0xFFFF, $ip, $opSize);
            }
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
