<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class In_ implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xE4, 0xE5, 0xEC, 0xED]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $isByte = ($opcode === 0xE4 || $opcode === 0xEC);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();

        $port = match ($opcode) {
            0xE4, 0xE5 => $memory->byte(),
            0xEC, 0xED => $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asByte() & 0xFFFF,
        };

        // assertIoPermission handles both IOPL and I/O bitmap checks
        $this->assertIoPermission($runtime, $port, $opSize);

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
