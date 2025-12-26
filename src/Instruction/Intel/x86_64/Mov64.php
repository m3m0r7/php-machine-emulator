<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86_64;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * MOV instruction for 64-bit mode.
 *
 * Handles 64-bit MOV variants with REX.W prefix:
 * - 0x89: MOV r/m64, r64 (with REX.W)
 * - 0x8B: MOV r64, r/m64 (with REX.W)
 * - 0xB8-0xBF: MOV r64, imm64 (with REX.W)
 * - 0xC7: MOV r/m64, imm32 (sign-extended, with REX.W)
 *
 * Without REX.W, these behave as 32-bit MOV instructions.
 */
class Mov64 implements InstructionInterface
{
    use Instructable64;

    public function opcodes(): array
    {
        return [
            0x89, // MOV r/m64, r64
            0x8B, // MOV r64, r/m64
            0xB8, 0xB9, 0xBA, 0xBB, 0xBC, 0xBD, 0xBE, 0xBF, // MOV r64, imm64
            0xC7, // MOV r/m64, imm32 (sign-extended)
        ];
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcode = $opcodes[0];
        $cpu = $runtime->context()->cpu();

        // In non-64-bit mode, delegate to standard x86 MOV
        if (!$cpu->isLongMode() || $cpu->isCompatibilityMode()) {
            [$instruction, ] = $this->instructionList->x86()->findInstruction($opcodes);
            return $instruction->process($runtime, $opcodes);
        }

        // If not REX.W, use 32-bit operation (zero-extended to 64-bit)
        $is64Bit = $cpu->rexW();

        return match ($opcode) {
            0x89 => $this->movRmReg($runtime, $is64Bit),
            0x8B => $this->movRegRm($runtime, $is64Bit),
            0xB8, 0xB9, 0xBA, 0xBB, 0xBC, 0xBD, 0xBE, 0xBF => $this->movRegImm($runtime, $opcode, $is64Bit),
            0xC7 => $this->movRmImm($runtime, $is64Bit),
            default => ExecutionStatus::SUCCESS,
        };
    }

    /**
     * MOV r/m64, r64 (0x89)
     */
    private function movRmReg(RuntimeInterface $runtime, bool $is64Bit): ExecutionStatus
    {
        $memory = $this->enhanceReader($runtime);
        $modRegRM = $memory->byteAsModRegRM();
        $cpu = $runtime->context()->cpu();

        $size = $is64Bit ? 64 : 32;

        // Read from reg field (with REX.R)
        $regCode = $modRegRM->registerOrOPCode();
        if ($cpu->rexR()) {
            $regCode |= 0b1000;
        }
        $regType = $this->getRegisterType64($regCode);
        $value = $runtime->memoryAccessor()->fetch($regType)->asBytesBySize($size);

        // Write to r/m field
        $this->writeRm64($runtime, $memory, $modRegRM, $value, $size);

        return ExecutionStatus::SUCCESS;
    }

    /**
     * MOV r64, r/m64 (0x8B)
     */
    private function movRegRm(RuntimeInterface $runtime, bool $is64Bit): ExecutionStatus
    {
        $memory = $this->enhanceReader($runtime);
        $modRegRM = $memory->byteAsModRegRM();
        $cpu = $runtime->context()->cpu();

        $size = $is64Bit ? 64 : 32;

        // Read from r/m field
        $value = $this->readRm64($runtime, $memory, $modRegRM, $size);

        // Write to reg field (with REX.R)
        $regCode = $modRegRM->registerOrOPCode();
        if ($cpu->rexR()) {
            $regCode |= 0b1000;
        }
        $regType = $this->getRegisterType64($regCode);

        if ($is64Bit) {
            $runtime->memoryAccessor()->writeBySize($regType, $value, 64);
        } else {
            // 32-bit write zero-extends to 64-bit
            $runtime->memoryAccessor()->writeBySize($regType, $value & 0xFFFFFFFF, 64);
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * MOV r64, imm64 (0xB8-0xBF with REX.W)
     */
    private function movRegImm(RuntimeInterface $runtime, int $opcode, bool $is64Bit): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();
        $memory = $this->enhanceReader($runtime);

        // Get register code from opcode
        $regCode = $opcode & 0b111;
        if ($cpu->rexB()) {
            $regCode |= 0b1000;
        }

        $regType = $this->getRegisterType64($regCode);

        if ($is64Bit) {
            // Read 64-bit immediate (little-endian)
            $immBytes = $memory->read(8);
            $imm = unpack('Pimm', $immBytes)['imm'];
            $runtime->memoryAccessor()->writeBySize($regType, $imm, 64);
        } else {
            // Read 32-bit immediate, zero-extend
            $imm = $memory->dword() & 0xFFFFFFFF;
            $runtime->memoryAccessor()->writeBySize($regType, $imm, 64);
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * MOV r/m64, imm32 (0xC7 with REX.W)
     * The immediate is sign-extended to 64 bits.
     */
    private function movRmImm(RuntimeInterface $runtime, bool $is64Bit): ExecutionStatus
    {
        $memory = $this->enhanceReader($runtime);
        $modRegRM = $memory->byteAsModRegRM();

        // Read 32-bit immediate
        // For r/m address calculation, we need to consume displacement first
        $cpu = $runtime->context()->cpu();
        $rmCode = $modRegRM->registerOrMemoryAddress();
        if ($cpu->rexB()) {
            $rmCode |= 0b1000;
        }

        $size = $is64Bit ? 64 : 32;

        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            // Register mode: read immediate after ModR/M
            $imm32 = $memory->dword();

            if ($is64Bit) {
                // Sign-extend 32-bit to 64-bit
                $value = $this->signExtendImm32to64($imm32);
            } else {
                $value = $imm32 & 0xFFFFFFFF;
            }

            $regType = $this->getRegisterType64($rmCode);
            $runtime->memoryAccessor()->writeBySize($regType, $value, $is64Bit ? 64 : 64);
        } else {
            // Memory mode: calculate address, then read immediate
            $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
            $imm32 = $memory->dword();

            if ($is64Bit) {
                $value = $this->signExtendImm32to64($imm32);
                $this->writeMemory64($runtime, $address, $value);
            } else {
                $this->writeMemory32($runtime, $address, $imm32 & 0xFFFFFFFF);
            }
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * Sign-extend 32-bit immediate to 64-bit.
     */
    private function signExtendImm32to64(int $value): int
    {
        if (($value & 0x80000000) !== 0) {
            return $value | 0xFFFFFFFF00000000;
        }
        return $value & 0xFFFFFFFF;
    }
}
