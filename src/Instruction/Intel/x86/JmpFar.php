<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class JmpFar implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xEA];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $opSize = $runtime->context()->cpu()->operandSize();
        $offset = $opSize === 32 ? $reader->dword() : $reader->short();
        $segment = $reader->short();

        $runtime->option()->logger()->debug(sprintf('JMP FAR: segment=0x%04X offset=0x%04X', $segment, $offset));

        if ($runtime->option()->shouldChangeOffset()) {
            if ($runtime->context()->cpu()->isProtectedMode()) {
                $gate = $this->readCallGateDescriptor($runtime, $segment);
                if ($gate !== null) {
                    $currentCs = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte();
                    $returnOffset = $this->codeOffsetFromLinear($runtime, $currentCs, $runtime->streamReader()->offset(), $opSize);
                    $this->callThroughGate($runtime, $gate, $returnOffset, $currentCs, $opSize, pushReturn: false, copyParams: false);
                    return ExecutionStatus::SUCCESS;
                }

                $descriptor = $this->resolveCodeDescriptor($runtime, $segment);
                $newCpl = $this->computeCplForTransfer($runtime, $segment, $descriptor);
                $linearTarget = $this->linearCodeAddress($runtime, $segment, $offset, $opSize);
                $runtime->streamReader()->setOffset($linearTarget);
                $this->writeCodeSegment($runtime, $segment, $newCpl, $descriptor);
                return ExecutionStatus::SUCCESS;
            }

            $linearTarget = $this->linearCodeAddress($runtime, $segment, $offset, $opSize);
            $runtime->option()->logger()->debug(sprintf('JMP FAR: linearTarget=0x%05X', $linearTarget));
            $runtime->streamReader()->setOffset($linearTarget);
            $this->writeCodeSegment($runtime, $segment);
        }

        return ExecutionStatus::SUCCESS;
    }
}
