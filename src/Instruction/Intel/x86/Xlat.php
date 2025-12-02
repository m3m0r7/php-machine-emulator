<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * XLAT / XLATB - Table Look-up Translation
 *
 * AL = [DS:BX + AL] (16-bit) or [DS:EBX + AL] (32-bit)
 *
 * Uses the byte at DS:(E)BX + unsigned AL as an index into a table,
 * and loads the result into AL.
 */
class Xlat implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xD7];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $ma = $runtime->memoryAccessor();
        $addrSize = $runtime->context()->cpu()->addressSize();

        // Get AL value (unsigned)
        $al = $ma->fetch(RegisterType::EAX)->asLowBit();

        // Get base address from BX or EBX depending on address size
        $base = $addrSize === 32
            ? $ma->fetch(RegisterType::EBX)->asBytesBySize(32)
            : $ma->fetch(RegisterType::EBX)->asBytesBySize(16);

        // Calculate effective address: DS:(E)BX + AL
        $segment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;
        $offset = ($base + $al) & ($addrSize === 32 ? 0xFFFFFFFF : 0xFFFF);
        $address = $this->segmentOffsetAddress($runtime, $segment, $offset);

        // Read byte from table and store in AL
        $value = $this->readMemory8($runtime, $address);
        $this->write8BitRegister($runtime, 0, $value); // AL = register 0

        return ExecutionStatus::SUCCESS;
    }
}
