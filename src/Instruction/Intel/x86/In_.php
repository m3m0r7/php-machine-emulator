<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class In_ implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xE4, 0xE5, 0xEC, 0xED];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhanced = new EnhanceStreamReader($runtime->memory());
        $isByte = ($opcode === 0xE4 || $opcode === 0xEC);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();

        $port = match ($opcode) {
            0xE4, 0xE5 => $enhanced->streamReader()->byte(),
            0xEC, 0xED => $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asByte() & 0xFFFF,
        };

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $cpl = $runtime->context()->cpu()->cpl();
            $iopl = $runtime->context()->cpu()->iopl();
            if ($cpl > $iopl) {
                throw new FaultException(0x0D, 0, 'IN privilege check failed');
            }
            $this->assertIoPermission($runtime, $port, $opSize);
        }

        $value = $this->readPort($runtime, $port, $opSize);

        if ($isByte) {
            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $value);
        } elseif ($opSize === 32) {
            $runtime->memoryAccessor()->writeBySize(RegisterType::EAX, $value, 32);
        } else {
            $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $value);
        }

        return ExecutionStatus::SUCCESS;
    }

}
