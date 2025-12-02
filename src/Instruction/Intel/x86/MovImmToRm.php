<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovImmToRm implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xC6, 0xC7];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $enhancedStreamReader->byteAsModRegRM();
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
                $value = $enhancedStreamReader->streamReader()->byte();
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $value);
            } else {
                // Memory mode: first calculate the address (this consumes any displacement bytes)
                $linearAddress = $this->rmLinearAddress($runtime, $enhancedStreamReader, $modRegRM);
                // Then read the immediate value
                $value = $enhancedStreamReader->streamReader()->byte();
                // Debug: log mov byte [rm], imm8 operations
                $runtime->option()->logger()->debug(sprintf(
                    'MOV byte [rm], imm8: mode=%d rm=%d linearAddr=0x%05X value=0x%02X (char=%s)',
                    $mode,
                    $modRegRM->registerOrMemoryAddress(),
                    $linearAddress,
                    $value,
                    $value >= 0x20 && $value < 0x7F ? chr($value) : '.'
                ));
                // Write the value to the calculated address
                $this->writeMemory8($runtime, $linearAddress, $value);
            }
        } else {
            if ($isRegister) {
                // Register mode: just read immediate and write to register
                $value = $size === 32 ? $enhancedStreamReader->dword() : $enhancedStreamReader->short();
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $value, $size);
            } else {
                // Memory mode: first calculate the address (this consumes any displacement bytes)
                $linearAddress = $this->rmLinearAddress($runtime, $enhancedStreamReader, $modRegRM);
                // Then read the immediate value
                $value = $size === 32 ? $enhancedStreamReader->dword() : $enhancedStreamReader->short();
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
