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

        $value = $this->readRm8($runtime, $enhancedStreamReader, $modRegRM);

        // Debug: log MOV r8, rm8 for string comparison analysis
        $mode = $modRegRM->mode();
        $rm = $modRegRM->registerOrMemoryAddress();
        $destReg = $modRegRM->destination();
        $destName = match ($destReg) {
            0 => 'AL', 1 => 'CL', 2 => 'DL', 3 => 'BL',
            4 => 'AH', 5 => 'CH', 6 => 'DH', 7 => 'BH',
        };
        $rmName = match ($rm) {
            0b100 => '[SI]', 0b101 => '[DI]', default => 'other',
        };
        if ($mode === 0 && ($rm === 0b100 || $rm === 0b101)) {
            $regType = $rm === 0b100 ? RegisterType::ESI : RegisterType::EDI;
            $offset = $runtime->memoryAccessor()->fetch($regType)->asByte();
            $ds = $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte();
            $linear = ($ds << 4) + $offset;
            $runtime->option()->logger()->debug(sprintf(
                'MOV %s, %s: DS=0x%04X offset=0x%04X linear=0x%05X value=0x%02X (%s)',
                $destName,
                $rmName,
                $ds,
                $offset,
                $linear,
                $value,
                $value >= 0x20 && $value < 0x7F ? chr($value) : '.'
            ));
        }

        $this->write8BitRegister($runtime, $modRegRM->destination(), $value, updateFlags: false);

        return ExecutionStatus::SUCCESS;
    }
}
