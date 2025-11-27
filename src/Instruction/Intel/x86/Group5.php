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
        $size = $runtime->context()->cpu()->operandSize();
        $value = $this->readRm($runtime, $reader, $modRegRM, $size);
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = ($value + 1) & $mask;
        $this->writeRm($runtime, $reader, $modRegRM, $result, $size);
        $runtime->memoryAccessor()->updateFlags($result, $size);
        // NOTE: Carry flag unaffected by INC.
        return ExecutionStatus::SUCCESS;
    }

    protected function dec(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $size = $runtime->context()->cpu()->operandSize();
        $value = $this->readRm($runtime, $reader, $modRegRM, $size);
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = ($value - 1) & $mask;
        $this->writeRm($runtime, $reader, $modRegRM, $result, $size);
        $runtime->memoryAccessor()->updateFlags($result, $size);
        return ExecutionStatus::SUCCESS;
    }

    protected function callNearRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $size = $runtime->context()->cpu()->operandSize();
        $target = $this->readRm($runtime, $reader, $modRegRM, $size);
        $pos = $runtime->streamReader()->offset();

        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $pos, $runtime->context()->cpu()->operandSize());

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime->streamReader()->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function jmpNearRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $size = $runtime->context()->cpu()->operandSize();
        $target = $this->readRm($runtime, $reader, $modRegRM, $size);

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime->streamReader()->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function callFarRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $addr = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $opSize = $runtime->context()->cpu()->operandSize();
        $offset = $opSize === 32 ? $this->readMemory32($runtime, $addr) : $this->readMemory16($runtime, $addr);
        $segment = $this->readMemory16($runtime, $addr + ($opSize === 32 ? 4 : 2));

        $pos = $runtime->streamReader()->offset();

        $size = $runtime->context()->cpu()->operandSize();

        $currentCs = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte();
        $returnOffset = $this->codeOffsetFromLinear($runtime, $currentCs, $pos, $size);

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $gate = $this->readCallGateDescriptor($runtime, $segment);
            if ($gate !== null) {
                $this->callThroughGate($runtime, $gate, $returnOffset, $currentCs, $size);
                return ExecutionStatus::SUCCESS;
            }
        }

        // push return CS:IP on current stack
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $currentCs, $size);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $returnOffset, $size);

        if ($runtime->option()->shouldChangeOffset()) {
            if ($runtime->context()->cpu()->isProtectedMode()) {
                $descriptor = $this->resolveCodeDescriptor($runtime, $segment);
                $newCpl = $this->computeCplForTransfer($runtime, $segment, $descriptor);
                $linearTarget = $this->linearCodeAddress($runtime, $segment, $offset, $opSize);
                $runtime->streamReader()->setOffset($linearTarget);
                $this->writeCodeSegment($runtime, $segment, $newCpl, $descriptor);
            } else {
                $linearTarget = $this->linearCodeAddress($runtime, $segment, $offset, $opSize);
                $runtime->streamReader()->setOffset($linearTarget);
                $this->writeCodeSegment($runtime, $segment);
            }
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function jmpFarRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $addr = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $opSize = $runtime->context()->cpu()->operandSize();
        $offset = $opSize === 32 ? $this->readMemory32($runtime, $addr) : $this->readMemory16($runtime, $addr);
        $segment = $this->readMemory16($runtime, $addr + ($opSize === 32 ? 4 : 2));

        if ($runtime->option()->shouldChangeOffset()) {
            if ($runtime->context()->cpu()->isProtectedMode()) {
                $gate = $this->readCallGateDescriptor($runtime, $segment);
                if ($gate !== null) {
                    $currentCs = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte();
                    $returnOffset = $this->codeOffsetFromLinear($runtime, $currentCs, $runtime->streamReader()->offset(), $opSize);
                    $this->callThroughGate($runtime, $gate, $returnOffset, $currentCs, $opSize, pushReturn: false, copyParams: false);
                } else {
                    $descriptor = $this->resolveCodeDescriptor($runtime, $segment);
                    $newCpl = $this->computeCplForTransfer($runtime, $segment, $descriptor);
                    $linearTarget = $this->linearCodeAddress($runtime, $segment, $offset, $opSize);
                    $runtime->streamReader()->setOffset($linearTarget);
                    $this->writeCodeSegment($runtime, $segment, $newCpl, $descriptor);
                }
            } else {
                $linearTarget = $this->linearCodeAddress($runtime, $segment, $offset, $opSize);
                $runtime->streamReader()->setOffset($linearTarget);
                $this->writeCodeSegment($runtime, $segment);
            }
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function push(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $size = $runtime->context()->cpu()->operandSize();
        $value = $this->readRm($runtime, $reader, $modRegRM, $size);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $value, $size);
        return ExecutionStatus::SUCCESS;
    }
}
