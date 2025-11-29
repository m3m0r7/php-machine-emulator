<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Out_ implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xE6, 0xE7, 0xEE, 0xEF];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhanced = new EnhanceStreamReader($runtime->memory());

        $port = match ($opcode) {
            0xE6, 0xE7 => $enhanced->streamReader()->byte(),
            0xEE, 0xEF => $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asByte() & 0xFFFF,
        };

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $cpl = $runtime->context()->cpu()->cpl();
            $iopl = $runtime->context()->cpu()->iopl();
            if ($cpl > $iopl) {
                throw new FaultException(0x0D, 0, 'OUT privilege check failed');
            }
            $this->assertIoPermission($runtime, $port, ($opcode === 0xE6 || $opcode === 0xEE) ? 8 : 16);
        }

        $value = ($opcode === 0xE6 || $opcode === 0xEE)
            ? $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit()
            : $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte();

        $this->writePort($runtime, $port, $value, ($opcode === 0xE6 || $opcode === 0xEE) ? 8 : 16);

        return ExecutionStatus::SUCCESS;
    }

}
