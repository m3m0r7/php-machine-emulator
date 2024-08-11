<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovRegToReg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [
            0x88,
        ];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $enhancedStreamReader->byteAsModRegRM();

        if (ModType::from($modRegRM->mode()) !== ModType::NO_DISPLACEMENT_OR_16BIT_DISPLACEMENT) {
            throw new ExecutionException(
                sprintf('The addressing mode (0b%s) is not supported yet', decbin($modRegRM->mode()))
            );
        }

        $proxiedStreamReader = $runtime->streamReader()->proxy();
        $register = $modRegRM->registerOrMemoryAddress();

        // TODO: Here is 16 bit addressing mode only. You need to fix/implement for 32 bit protection mode here
        if ($register === 0b101) {
            // NOTE: Here is incorrect implementation.
            //       Actually here need to use DI (0b101) register but here is replacing EDI (0b111) register
            $register = RegisterType::EDI;
        }

        $offset = $runtime->memoryAccessor()
            ->fetch($register)
            ->asByte();

        $runtime
            ->memoryAccessor()
            ->allocate(
                $offset,
                safe: false,
            );

        $runtime
            ->memoryAccessor()
            ->writeToLowBit(
                $offset,
                $runtime
                    ->memoryAccessor()
                    ->fetch($modRegRM->registerOrOPCode())
                    ->asLowBit(),
            );

        return ExecutionStatus::SUCCESS;
    }
}
