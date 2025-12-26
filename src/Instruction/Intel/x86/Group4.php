<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Group4 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xFE]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        return match ($modRegRM->digit()) {
            0x0 => $this->inc($runtime, $memory, $modRegRM),
            0x1 => $this->dec($runtime, $memory, $modRegRM),
            default => throw new ExecutionException(sprintf('Group4 digit 0x%X not implemented', $modRegRM->digit())),
        };
    }

    protected function inc(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $ma = $runtime->memoryAccessor();

        // Preserve CF - INC does not affect carry flag
        $savedCf = $ma->shouldCarryFlag();

        if ($isRegister) {
            $rm = $modRegRM->registerOrMemoryAddress();
            if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()) {
                $value = $this->read8BitRegister64($runtime, $rm, true, $cpu->rexB());
            } else {
                $value = $this->read8BitRegister($runtime, $rm);
            }
            $result = ($value + 1) & 0xFF;
            if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()) {
                $this->write8BitRegister64($runtime, $rm, $result, true, $cpu->rexB());
            } else {
                $this->write8BitRegister($runtime, $rm, $result);
            }
        } else {
            // Calculate address once to avoid consuming displacement bytes twice
            $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
            $value = $this->readMemory8($runtime, $address);
            $result = ($value + 1) & 0xFF;
            $this->writeMemory8($runtime, $address, $result);
        }

        $af = (($value & 0x0F) + 1) > 0x0F;
        $ma->updateFlags($result, 8);
        $ma->setAuxiliaryCarryFlag($af);
        $ma->setCarryFlag($savedCf);

        // OF for INC: set when result is 0x80 (incrementing 0x7F to 0x80)
        $ma->setOverflowFlag($result === 0x80);

        $runtime->option()->logger()->debug(sprintf('INC r/m8: %d -> %d (rm=%d)', $value, $result, $modRegRM->registerOrMemoryAddress()));
        return ExecutionStatus::SUCCESS;
    }

    protected function dec(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $ma = $runtime->memoryAccessor();

        // Preserve CF - DEC does not affect carry flag
        $savedCf = $ma->shouldCarryFlag();

        if ($isRegister) {
            $rm = $modRegRM->registerOrMemoryAddress();
            if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()) {
                $value = $this->read8BitRegister64($runtime, $rm, true, $cpu->rexB());
            } else {
                $value = $this->read8BitRegister($runtime, $rm);
            }
            $result = ($value - 1) & 0xFF;
            if ($cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->hasRex()) {
                $this->write8BitRegister64($runtime, $rm, $result, true, $cpu->rexB());
            } else {
                $this->write8BitRegister($runtime, $rm, $result);
            }
        } else {
            // Calculate address once to avoid consuming displacement bytes twice
            $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
            $value = $this->readMemory8($runtime, $address);
            $result = ($value - 1) & 0xFF;
            $this->writeMemory8($runtime, $address, $result);
        }

        $af = (($value & 0x0F) === 0);
        $ma->updateFlags($result, 8);
        $ma->setAuxiliaryCarryFlag($af);
        $ma->setCarryFlag($savedCf);

        // OF for DEC: set when result is 0x7F (decrementing 0x80 to 0x7F)
        $ma->setOverflowFlag($result === 0x7F);

        return ExecutionStatus::SUCCESS;
    }
}
