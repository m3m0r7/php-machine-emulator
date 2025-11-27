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

class Group1 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [
            0x80,
            0x81,
            0x82,
            0x83,
        ];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $enhancedStreamReader->byteAsModRegRM();

        $size = $runtime->runtimeOption()->context()->operandSize();

        $operand = $this->isSignExtendedWordOperation($opcode)
            ? $enhancedStreamReader->streamReader()->signedByte()
            : ($this->isByteOperation($opcode)
                ? $enhancedStreamReader->streamReader()->byte()
                : ($size === 32 ? $enhancedStreamReader->dword() : $enhancedStreamReader->short()));

        match ($modRegRM->digit()) {
            0x0 => $this->add($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size),
            0x1 => $this->or($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size),
            0x2 => $this->adc($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size),
            0x3 => $this->sbb($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size),
            0x4 => $this->and($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size),
            0x5 => $this->sub($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size),
            0x6 => $this->xor($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size),
            0x7 => $this->cmp($runtime, $enhancedStreamReader, $modRegRM, $opcode, $operand, $size),
        };

        return ExecutionStatus::SUCCESS;
    }

    private function isByteOperation(int $opcode): bool
    {
        return $opcode === 0x80 || $opcode === 0x82;
    }

    private function isSignExtendedWordOperation(int $opcode): bool
    {
        return $opcode === 0x83;
    }

    protected function add(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $original = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readRm8($runtime, $streamReader, $modRegRM);
            $result = $original + $operand;
            $this->writeRm8($runtime, $streamReader, $modRegRM, $result);
            $runtime->memoryAccessor()->setCarryFlag($result > 0xFF);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $original = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER
            ? $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize)
            : $this->readRm($runtime, $streamReader, $modRegRM, $opSize);

        $result = $original + $operand;

        $this->writeRm($runtime, $streamReader, $modRegRM, $result, $opSize);
        $runtime->memoryAccessor()->setCarryFlag($result > $mask);

        return ExecutionStatus::SUCCESS;
    }

    protected function or(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $result = $this->readRm8($runtime, $streamReader, $modRegRM) | ($operand & 0xFF);
            $this->writeRm8($runtime, $streamReader, $modRegRM, $result);
            $runtime->memoryAccessor()->setCarryFlag(false);

            return ExecutionStatus::SUCCESS;
        }

        $left = $this->readRm($runtime, $streamReader, $modRegRM, $opSize);
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = ($left | $operand) & $mask;

        $this->writeRm($runtime, $streamReader, $modRegRM, $result, $opSize);
        $runtime->memoryAccessor()->setCarryFlag(false);

        return ExecutionStatus::SUCCESS;
    }

    protected function adc(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize): ExecutionStatus
    {
        $carry = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

        if ($this->isByteOperation($opcode)) {
            $left = $this->readRm8($runtime, $streamReader, $modRegRM);
            $result = $left + ($operand & 0xFF) + $carry;
            $this->writeRm8($runtime, $streamReader, $modRegRM, $result);
            $runtime->memoryAccessor()->setCarryFlag($result > 0xFF);

            return ExecutionStatus::SUCCESS;
        }

        $left = $this->readRm($runtime, $streamReader, $modRegRM, $opSize);
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = $left + $operand + $carry;

        $this->writeRm($runtime, $streamReader, $modRegRM, $result, $opSize);
        $runtime->memoryAccessor()->setCarryFlag($result > $mask);

        return ExecutionStatus::SUCCESS;
    }

    protected function sbb(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize): ExecutionStatus
    {
        $borrow = $runtime->memoryAccessor()->shouldCarryFlag() ? 1 : 0;

        if ($this->isByteOperation($opcode)) {
            $left = $this->readRm8($runtime, $streamReader, $modRegRM);
            $result = ($left - ($operand & 0xFF) - $borrow) & 0xFF;
            $this->writeRm8($runtime, $streamReader, $modRegRM, $result);
            $runtime->memoryAccessor()->setCarryFlag(($left - ($operand & 0xFF) - $borrow) < 0);

            return ExecutionStatus::SUCCESS;
        }

        $left = $this->readRm($runtime, $streamReader, $modRegRM, $opSize);
        $calc = $left - $operand - $borrow;
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = $calc & $mask;

        $this->writeRm($runtime, $streamReader, $modRegRM, $result, $opSize);
        $runtime->memoryAccessor()->setCarryFlag($calc < 0);

        return ExecutionStatus::SUCCESS;
    }

    protected function and(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $result = $this->readRm8($runtime, $streamReader, $modRegRM) & ($operand & 0xFF);
            $this->writeRm8($runtime, $streamReader, $modRegRM, $result);
            $runtime->memoryAccessor()->setCarryFlag(false);

            return ExecutionStatus::SUCCESS;
        }

        $left = $this->readRm($runtime, $streamReader, $modRegRM, $opSize);
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = ($left & $operand) & $mask;

        $this->writeRm($runtime, $streamReader, $modRegRM, $result, $opSize);
        $runtime->memoryAccessor()->setCarryFlag(false);

        return ExecutionStatus::SUCCESS;
    }

    protected function sub(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $left = $this->readRm8($runtime, $streamReader, $modRegRM);
            $result = ($left - ($operand & 0xFF)) & 0xFF;
            $this->writeRm8($runtime, $streamReader, $modRegRM, $result);
            $runtime->memoryAccessor()->setCarryFlag(($left - ($operand & 0xFF)) < 0);

            return ExecutionStatus::SUCCESS;
        }

        $left = $this->readRm($runtime, $streamReader, $modRegRM, $opSize);
        $calc = $left - $operand;
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = $calc & $mask;

        $this->writeRm($runtime, $streamReader, $modRegRM, $result, $opSize);
        $runtime->memoryAccessor()->setCarryFlag($calc < 0);

        return ExecutionStatus::SUCCESS;
    }

    protected function xor(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $result = $this->readRm8($runtime, $streamReader, $modRegRM) ^ ($operand & 0xFF);
            $this->writeRm8($runtime, $streamReader, $modRegRM, $result);
            $runtime->memoryAccessor()->setCarryFlag(false);

            return ExecutionStatus::SUCCESS;
        }

        $left = $this->readRm($runtime, $streamReader, $modRegRM, $opSize);
        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = ($left ^ $operand) & $mask;

        $this->writeRm($runtime, $streamReader, $modRegRM, $result, $opSize);
        $runtime->memoryAccessor()->setCarryFlag(false);

        return ExecutionStatus::SUCCESS;
    }

    protected function cmp(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, int $opcode, int $operand, int $opSize): ExecutionStatus
    {
        if ($this->isByteOperation($opcode)) {
            $leftHand = $this->readRm8($runtime, $streamReader, $modRegRM) & 0xFF;
            $runtime
                ->memoryAccessor()
            ->updateFlags($leftHand - ($operand & 0xFF), 8)
            ->setCarryFlag($leftHand < ($operand & 0xFF));

            return ExecutionStatus::SUCCESS;
        }

        $leftHand = $this->readRm($runtime, $streamReader, $modRegRM, $opSize);

        $runtime
            ->memoryAccessor()
            ->updateFlags($leftHand - $operand, $opSize)
            ->setCarryFlag($leftHand < $operand);

        return ExecutionStatus::SUCCESS;
    }
}
