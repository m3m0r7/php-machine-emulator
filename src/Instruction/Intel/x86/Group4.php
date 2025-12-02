<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Group4 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xFE];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $reader->byteAsModRegRM();

        return match ($modRegRM->digit()) {
            0x0 => $this->inc($runtime, $reader, $modRegRM),
            0x1 => $this->dec($runtime, $reader, $modRegRM),
            default => throw new ExecutionException(sprintf('Group4 digit 0x%X not implemented', $modRegRM->digit())),
        };
    }

    protected function inc(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $ma = $runtime->memoryAccessor();

        if ($isRegister) {
            $value = $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress());
            $result = ($value + 1) & 0xFF;
            $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
        } else {
            // Calculate address once to avoid consuming displacement bytes twice
            $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
            $value = $this->readMemory8($runtime, $address);
            $result = ($value + 1) & 0xFF;
            $this->writeMemory8($runtime, $address, $result);
        }

        // Preserve CF - INC does not affect carry flag
        $savedCf = $ma->shouldCarryFlag();
        $ma->updateFlags($result, 8);
        $ma->setCarryFlag($savedCf);

        // OF for INC: set when result is 0x80 (incrementing 0x7F to 0x80)
        $ma->setOverflowFlag($result === 0x80);

        $runtime->option()->logger()->debug(sprintf('INC r/m8: %d -> %d (rm=%d)', $value, $result, $modRegRM->registerOrMemoryAddress()));
        return ExecutionStatus::SUCCESS;
    }

    protected function dec(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $ma = $runtime->memoryAccessor();

        if ($isRegister) {
            $value = $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress());
            $result = ($value - 1) & 0xFF;
            $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
        } else {
            // Calculate address once to avoid consuming displacement bytes twice
            $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
            $value = $this->readMemory8($runtime, $address);
            $result = ($value - 1) & 0xFF;
            $this->writeMemory8($runtime, $address, $result);
        }

        // Preserve CF - DEC does not affect carry flag
        $savedCf = $ma->shouldCarryFlag();
        $ma->updateFlags($result, 8);
        $ma->setCarryFlag($savedCf);

        // OF for DEC: set when result is 0x7F (decrementing 0x80 to 0x7F)
        $ma->setOverflowFlag($result === 0x7F);

        return ExecutionStatus::SUCCESS;
    }
}
