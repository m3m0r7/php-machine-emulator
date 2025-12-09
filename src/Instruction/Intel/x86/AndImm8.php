<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class AndImm8 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x24];
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $operand = $runtime->memory()->byte();
        $al = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
        $result = $al & $operand;

        $runtime
            ->memoryAccessor()
            ->writeToLowBit(RegisterType::EAX, $result);

        // AND always clears CF and OF, updates SF/ZF/PF based on result
        $runtime->memoryAccessor()
            ->updateFlags($result, 8)
            ->setCarryFlag(false)
            ->setOverflowFlag(false);

        return ExecutionStatus::SUCCESS;
    }
}
