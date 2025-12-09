<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class PopRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x8F]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $reader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $reader->byteAsModRegRM();

        if ($modRegRM->digit() !== 0) {
            throw new ExecutionException('POP r/m16 with invalid digit');
        }

        $opSize = $runtime->context()->cpu()->operandSize();
        $value = $runtime->memoryAccessor()->pop(RegisterType::ESP, $opSize)->asBytesBySize($opSize);

        if ($opSize === 32) {
            $this->writeRm($runtime, $reader, $modRegRM, $value, 32);
        } else {
            $this->writeRm16($runtime, $reader, $modRegRM, $value);
        }

        return ExecutionStatus::SUCCESS;
    }
}
