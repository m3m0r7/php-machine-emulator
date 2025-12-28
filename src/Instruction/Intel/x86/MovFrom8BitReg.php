<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovFrom8BitReg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x88]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        $value = $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode());

        // Debug: Log CS:0x0137 writes
        $ip = $memory->offset();
        $debugThisInstruction = ($ip >= 0x0880 && $ip <= 0x0890);
        if ($debugThisInstruction) {
            $cpuOverride = $runtime->context()->cpu()->segmentOverride();
            $cs = $runtime->memoryAccessor()->fetch(\PHPMachineEmulator\Instruction\RegisterType::CS)->asByte();
            $runtime->option()->logger()->debug(sprintf(
                'MOV [r/m8], r8 DEBUG: IP=0x%05X value=0x%02X segOverride=%s CS=0x%04X modRM=0x%02X',
                $ip,
                $value,
                $cpuOverride?->name ?? 'none',
                $cs,
                $modRegRM->mode() << 6 | $modRegRM->registerOrOPCode() << 3 | $modRegRM->registerOrMemoryAddress()
            ));
        }

        $this->writeRm8WithDebug(
            $runtime,
            $memory,
            $modRegRM,
            $value,
            $debugThisInstruction
        );

        return ExecutionStatus::SUCCESS;
    }
}
