<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class CallFar implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x9A];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $size = $runtime->context()->cpu()->operandSize();
        $offset = $size === 32 ? $reader->dword() : $reader->short();
        $segment = $reader->short();

        $pos = $runtime->memory()->offset();

        $currentCs = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte();
        $returnOffset = $this->codeOffsetFromLinear($runtime, $currentCs, $pos, $size);

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $gate = $this->readCallGateDescriptor($runtime, $segment);
            if ($gate !== null) {
                $this->callThroughGate($runtime, $gate, $returnOffset, $currentCs, $size);
                return ExecutionStatus::SUCCESS;
            }
        }

        // push return CS:IP (address of next instruction)
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $currentCs, $size);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $returnOffset, $size);

        if ($runtime->option()->shouldChangeOffset()) {
            if ($runtime->context()->cpu()->isProtectedMode()) {
                $descriptor = $this->resolveCodeDescriptor($runtime, $segment);
                $newCpl = $this->computeCplForTransfer($runtime, $segment, $descriptor);
                $linearTarget = $this->linearCodeAddress($runtime, $segment, $offset, $size);
                $runtime->memory()->setOffset($linearTarget);
                $this->writeCodeSegment($runtime, $segment, $newCpl, $descriptor);
                return ExecutionStatus::SUCCESS;
            }

            $linearTarget = $this->linearCodeAddress($runtime, $segment, $offset, $size);
            $runtime->memory()->setOffset($linearTarget);
            $this->writeCodeSegment($runtime, $segment);
        }

        return ExecutionStatus::SUCCESS;
    }
}
