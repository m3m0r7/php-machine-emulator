<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovMem implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x8A];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $enhancedStreamReader
            ->byteAsModRegRM();

        if (ModType::from($modRegRM->mode()) !== ModType::NO_DISPLACEMENT_OR_16BIT_DISPLACEMENT) {
            throw new ExecutionException(
                sprintf('The addressing mode (0b%02s) is not supported yet', decbin($modRegRM->mode()))
            );
        }

        $source = $modRegRM->source();

        // TODO: Here is 16 bit addressing mode only. You need to fix/implement for 32 bit protection mode here
        if ($source === 0b100) {
            // NOTE: Here is incorrect implementation.
            //       Actually here need to use SI (0b100) register but here is replacing ESI (0b110) register
            $source = RegisterType::ESI;
        }

        $sourceOffset = $runtime
            ->memoryAccessor()
            ->fetch($source)
            ->asByte();

        $offset = $runtime
                ->addressMap()
                ->getDisk()
                ->entrypointOffset() + ($sourceOffset - $runtime->addressMap()->getOrigin());

        $proxiedStreamReader = $runtime
            ->streamReader()
            ->proxy();

        $proxiedStreamReader
            ->setOffset($offset);

        // TODO: here is to write low bits only
        $runtime
            ->memoryAccessor()
            ->writeToLowBit(
                $modRegRM
                    ->destination(),
                $proxiedStreamReader
                    ->byte(),
            );

        return ExecutionStatus::SUCCESS;
    }
}
