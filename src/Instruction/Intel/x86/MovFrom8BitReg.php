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

class MovFrom8BitReg implements InstructionInterface
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
        $enhancedStreamReader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $enhancedStreamReader->byteAsModRegRM();

        $value = $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode());

        // Debug: log mov [rm8], r8 operations
        $runtime->option()->logger()->debug(sprintf(
            'MOV [rm8], r8: mode=%d reg=%d rm=%d value=0x%02X (char=%s)',
            $modRegRM->mode(),
            $modRegRM->registerOrOPCode(),
            $modRegRM->registerOrMemoryAddress(),
            $value,
            $value >= 0x20 && $value < 0x7F ? chr($value) : '.'
        ));

        $this->writeRm8(
            $runtime,
            $enhancedStreamReader,
            $modRegRM,
            $value,
        );

        return ExecutionStatus::SUCCESS;
    }
}
