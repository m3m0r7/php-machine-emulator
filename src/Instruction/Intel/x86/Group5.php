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

class Group5 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xFF];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $reader->byteAsModRegRM();

        return match ($modRegRM->digit()) {
            0x0 => $this->inc($runtime, $reader, $modRegRM),
            0x1 => $this->dec($runtime, $reader, $modRegRM),
            0x2 => $this->callNearRm($runtime, $reader, $modRegRM),
            0x3 => $this->callFarRm($runtime, $reader, $modRegRM),
            0x4 => $this->jmpNearRm($runtime, $reader, $modRegRM),
            0x5 => $this->jmpFarRm($runtime, $reader, $modRegRM),
            0x6 => $this->push($runtime, $reader, $modRegRM),
            default => throw new ExecutionException(sprintf('Group5 digit 0x%X not implemented', $modRegRM->digit())),
        };
    }

    protected function inc(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $value = $this->readRm16($runtime, $reader, $modRegRM);
        $result = ($value + 1) & 0xFFFF;
        $this->writeRm16($runtime, $reader, $modRegRM, $result);
        // NOTE: Carry flag unaffected by INC.
        return ExecutionStatus::SUCCESS;
    }

    protected function dec(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $value = $this->readRm16($runtime, $reader, $modRegRM);
        $result = ($value - 1) & 0xFFFF;
        $this->writeRm16($runtime, $reader, $modRegRM, $result);
        return ExecutionStatus::SUCCESS;
    }

    protected function callNearRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $target = $this->readRm16($runtime, $reader, $modRegRM);
        $pos = $runtime->streamReader()->offset();

        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $pos, $runtime->runtimeOption()->context()->operandSize());

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime->streamReader()->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function jmpNearRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $target = $this->readRm16($runtime, $reader, $modRegRM);

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime->streamReader()->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function callFarRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $addr = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $offset = $this->readMemory16($runtime, $addr);
        $segment = $this->readMemory16($runtime, $addr + 2);

        $pos = $runtime->streamReader()->offset();

        $size = $runtime->runtimeOption()->context()->operandSize();

        // push return CS:IP
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte(), $size);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $pos, $size);

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime->streamReader()->setOffset($offset);
            $runtime->memoryAccessor()->write16Bit(RegisterType::CS, $segment);
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function jmpFarRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $addr = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $offset = $this->readMemory16($runtime, $addr);
        $segment = $this->readMemory16($runtime, $addr + 2);

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime->streamReader()->setOffset($offset);
            $runtime->memoryAccessor()->write16Bit(RegisterType::CS, $segment);
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function push(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $value = $this->readRm16($runtime, $reader, $modRegRM);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $value);
        return ExecutionStatus::SUCCESS;
    }
}
