<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Xchg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x86, 0x87, 0x90, 0x91, 0x92, 0x93, 0x94, 0x95, 0x96, 0x97];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        if ($opcode >= 0x90) {
            // XCHG AX, r16 (0x90 + reg)
            $reg = ($opcode - 0x90);
            $target = ($this->instructionList->register())::find($reg);
            $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte();
            $rv = $runtime->memoryAccessor()->fetch($target)->asByte();

            // Debug: log XCHG when SI is involved
            if ($target === RegisterType::ESI) {
                $runtime->option()->logger()->debug(sprintf(
                    'XCHG AX, SI: AX=0x%04X SI=0x%04X (opcode=0x%02X)',
                    $ax, $rv, $opcode
                ));
            }

            $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit(RegisterType::EAX, $rv);
            $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit($target, $ax);
            return ExecutionStatus::SUCCESS;
        }

        $reader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $reader->byteAsModRegRM();

        if ($opcode === 0x86) {
            $rm = $this->readRm8($runtime, $reader, $modRegRM);
            $reg = $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode());

            $this->writeRm8($runtime, $reader, $modRegRM, $reg);
            $this->write8BitRegister($runtime, $modRegRM->registerOrOPCode(), $rm, updateFlags: false);

            return ExecutionStatus::SUCCESS;
        }

        $rm = $this->readRm16($runtime, $reader, $modRegRM);
        $reg = $runtime->memoryAccessor()->fetch($modRegRM->registerOrOPCode())->asByte();

        $this->writeRm16($runtime, $reader, $modRegRM, $reg);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit($modRegRM->registerOrOPCode(), $rm);

        return ExecutionStatus::SUCCESS;
    }
}
