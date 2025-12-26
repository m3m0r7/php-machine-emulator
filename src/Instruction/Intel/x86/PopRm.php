<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
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
        $hasOperandSizeOverridePrefix = in_array(self::PREFIX_OPERAND_SIZE, $opcodes, true);
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        if ($modRegRM->digit() !== 0) {
            throw new ExecutionException('POP r/m16 with invalid digit');
        }

        $cpu = $runtime->context()->cpu();

        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $popSize = $hasOperandSizeOverridePrefix ? 16 : 64;
            $value = $runtime->memoryAccessor()->pop(RegisterType::ESP, $popSize)->asBytesBySize($popSize);
            $this->writeRm($runtime, $memory, $modRegRM, $value, $popSize);
            return ExecutionStatus::SUCCESS;
        }

        $opSize = $cpu->operandSize();
        $value = $runtime->memoryAccessor()->pop(RegisterType::ESP, $opSize)->asBytesBySize($opSize);
        $this->writeRm($runtime, $memory, $modRegRM, $value, $opSize);

        return ExecutionStatus::SUCCESS;
    }
}
