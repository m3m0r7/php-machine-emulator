<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovImmToRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xC6, 0xC7]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();
        $size = $runtime->context()->cpu()->operandSize();

        // Intel spec says reg field should be 0, but some code may use other values
        // We'll log a warning but continue execution as MOV
        if ($modRegRM->registerOrOPCode() !== 0) {
            $runtime->option()->logger()->debug(sprintf(
                'MOV immediate to r/m: unexpected reg field %d (expected 0), treating as MOV',
                $modRegRM->registerOrOPCode()
            ));
        }

        $mode = $modRegRM->mode();
        $isRegister = ($mode === 3);

        if ($opcode === 0xC6) {
            if ($isRegister) {
                // Register mode: just read immediate and write to register
                $value = $memory->byte();
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $value);
            } else {
                // Memory mode: first calculate the address (this consumes any displacement bytes)
                $linearAddress = $this->rmLinearAddress($runtime, $memory, $modRegRM);
                // Then read the immediate value
                $value = $memory->byte();
                // Write the value to the calculated address
                $this->writeMemory8($runtime, $linearAddress, $value);
            }
        } else {
            if ($isRegister) {
                // Register mode: just read immediate and write to register
                $value = $size === 32 ? $memory->dword() : $memory->short();
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $value, $size);
            } else {
                // Memory mode: first calculate the address (this consumes any displacement bytes)
                $linearAddress = $this->rmLinearAddress($runtime, $memory, $modRegRM);
                // Then read the immediate value
                $value = $size === 32 ? $memory->dword() : $memory->short();
                // Write the value to the calculated address
                if ($size === 32) {
                    $this->writeMemory32($runtime, $linearAddress, $value);
                } else {
                    $this->writeMemory16($runtime, $linearAddress, $value);
                }
            }
        }

        return ExecutionStatus::SUCCESS;
    }
}
