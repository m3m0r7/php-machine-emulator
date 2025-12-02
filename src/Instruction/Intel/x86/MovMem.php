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
        $enhancedStreamReader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $enhancedStreamReader
            ->byteAsModRegRM();

        // Debug: check ModRM and address size before reading
        $addrSize = $runtime->context()->cpu()->addressSize();
        $mode = $modRegRM->mode();
        $rm = $modRegRM->registerOrMemoryAddress();
        $streamPosBefore = $runtime->memory()->offset();

        $value = $this->readRm8($runtime, $enhancedStreamReader, $modRegRM);

        // Debug: log MOV r8, rm8 for memory access
        $destReg = $modRegRM->destination();
        $destName = match ($destReg) {
            0 => 'AL', 1 => 'CL', 2 => 'DL', 3 => 'BL',
            4 => 'AH', 5 => 'CH', 6 => 'DH', 7 => 'BH',
        };
        // In 32-bit mode with rm=4, check SIB byte for addressing
        if ($addrSize === 32 && $mode === 0 && $rm === 0b100) {
            $edi = $runtime->memoryAccessor()->fetch(RegisterType::EDI)->asBytesBySize(32);
            $edx = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asBytesBySize(32);
            $esi = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asBytesBySize(32);
            $runtime->option()->logger()->debug(sprintf(
                'MOV %s, [SIB]: EDI=0x%08X EDX=0x%08X ESI=0x%08X value=0x%02X (%s)',
                $destName,
                $edi,
                $edx,
                $esi,
                $value,
                $value >= 0x20 && $value < 0x7F ? chr($value) : '.'
            ));
        }

        $this->write8BitRegister($runtime, $modRegRM->destination(), $value);

        return ExecutionStatus::SUCCESS;
    }
}
