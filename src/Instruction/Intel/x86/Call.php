<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Call implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xE8]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $enhancedStreamReader = new EnhanceStreamReader($runtime->memory());

        $opSize = $runtime->context()->cpu()->operandSize();
        $offset = $opSize === 32
            ? $enhancedStreamReader->signedDword()
            : $enhancedStreamReader->signedShort();

        $mask = $opSize === 32 ? 0xFFFFFFFF : 0xFFFF;

        // After reading the displacement, the stream offset now points to the next instruction.
        $nextLinear = $runtime->memory()->offset();
        $cs = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte();

        // Calculate return offset and target using proper segment base resolution
        // This handles both real mode (cs << 4) and protected mode (descriptor base)
        $returnOffset = $this->codeOffsetFromLinear($runtime, $cs, $nextLinear, $opSize);
        $targetOffset = ($returnOffset + $offset) & $mask;
        $targetLinear = $this->linearCodeAddress($runtime, $cs, $targetOffset, $opSize);
        $returnToPush = $returnOffset;

        // Push return address onto stack.
        $espBefore = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize($opSize);
        $runtime
            ->memoryAccessor()
            ->push(RegisterType::ESP, $returnToPush, $opSize);

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime
                ->memory()
                ->setOffset($targetLinear);
        }

        return ExecutionStatus::SUCCESS;
    }
}
