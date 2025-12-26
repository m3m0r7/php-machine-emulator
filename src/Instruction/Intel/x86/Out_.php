<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Out_ implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xE6, 0xE7, 0xEE, 0xEF]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $isByte = ($opcode === 0xE6 || $opcode === 0xEE);
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();

        $port = match ($opcode) {
            0xE6, 0xE7 => $memory->byte(),
            0xEE, 0xEF => $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asByte() & 0xFFFF,
        };

        // assertIoPermission handles both IOPL and I/O bitmap checks
        $this->assertIoPermission($runtime, $port, $opSize);

        $value = $isByte
            ? $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit()
            : ($opSize === 32
                ? $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize(32)
                : $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte());

        $this->writePort($runtime, $port, $value, $opSize);

        return ExecutionStatus::SUCCESS;
    }

}
