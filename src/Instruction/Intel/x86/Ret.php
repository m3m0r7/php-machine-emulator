<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\InstructionInterface;

class Ret implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xC3, 0xC2, 0xCB, 0xCA];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $popBytes = ($opcode === 0xC2 || $opcode === 0xCA)
            ? $runtime->streamReader()->short()
            : 0;

        $size = $runtime->runtimeOption()->context()->operandSize();

        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);
        $returnIp = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);

        if ($opcode === 0xCB || $opcode === 0xCA) {
            // FAR ret: pop CS as well
            $cs = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
            $ma->write16Bit(RegisterType::CS, $cs);
        }

        if ($popBytes > 0) {
            $ma->write16Bit(RegisterType::ESP, ($ma->fetch(RegisterType::ESP)->asByte() + $popBytes) & 0xFFFF);
        }

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime->streamReader()->setOffset($returnIp);
        }

        return ExecutionStatus::SUCCESS;
    }
}
