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
        $enhanced = new EnhanceStreamReader($runtime->streamReader());

        $port = match ($opcode) {
            0xE4, 0xE5 => $enhanced->streamReader()->byte(),
            0xEC, 0xED => $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asByte() & 0xFFFF,
        };

        if ($runtime->runtimeOption()->context()->isProtectedMode()) {
            $cpl = $runtime->runtimeOption()->context()->cpl();
            $iopl = $runtime->runtimeOption()->context()->iopl();
            if ($cpl > $iopl) {
                throw new FaultException(0x0D, 0, 'IN privilege check failed');
            }
            $this->assertIoPermission($runtime, $port, $opcode === 0xE4 || $opcode === 0xEC ? 8 : 16);
        }

        $value = $this->readPort($runtime, $port, $opcode === 0xE4 || $opcode === 0xEC ? 8 : 16);

        if ($opcode === 0xE4 || $opcode === 0xEC) {
            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $value);
        } else {
            $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $value);
        }

        return ExecutionStatus::SUCCESS;
    }

}
