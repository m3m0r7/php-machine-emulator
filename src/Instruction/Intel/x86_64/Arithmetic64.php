<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86_64;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * 64-bit arithmetic instructions (ADD, SUB, CMP, AND, OR, XOR).
 *
 * With REX.W prefix, these operate on 64-bit operands:
 * - 0x01: ADD r/m64, r64
 * - 0x03: ADD r64, r/m64
 * - 0x05: ADD RAX, imm32 (sign-extended)
 * - 0x29: SUB r/m64, r64
 * - 0x2B: SUB r64, r/m64
 * - 0x2D: SUB RAX, imm32 (sign-extended)
 * - 0x39: CMP r/m64, r64
 * - 0x3B: CMP r64, r/m64
 * - 0x3D: CMP RAX, imm32 (sign-extended)
 * - 0x21: AND r/m64, r64
 * - 0x23: AND r64, r/m64
 * - 0x25: AND RAX, imm32 (sign-extended)
 * - 0x09: OR r/m64, r64
 * - 0x0B: OR r64, r/m64
 * - 0x0D: OR RAX, imm32 (sign-extended)
 * - 0x31: XOR r/m64, r64
 * - 0x33: XOR r64, r/m64
 * - 0x35: XOR RAX, imm32 (sign-extended)
 */
class Arithmetic64 implements InstructionInterface
{
    use Instructable64;

    public function opcodes(): array
    {
        return [
            // ADD
            0x01, 0x03, 0x05,
            // SUB
            0x29, 0x2B, 0x2D,
            // CMP
            0x39, 0x3B, 0x3D,
            // AND
            0x21, 0x23, 0x25,
            // OR
            0x09, 0x0B, 0x0D,
            // XOR
            0x31, 0x33, 0x35,
        ];
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcode = $opcodes[0];
        $cpu = $runtime->context()->cpu();

        // In non-64-bit mode, delegate to standard x86
        if (!$cpu->isLongMode() || $cpu->isCompatibilityMode()) {
            [$instruction, ] = $this->instructionList->x86()->findInstruction($opcodes);
            return $instruction->process($runtime, $opcodes);
        }

        // Determine operation type
        $operation = match (true) {
            in_array($opcode, [0x01, 0x03, 0x05], true) => 'ADD',
            in_array($opcode, [0x29, 0x2B, 0x2D], true) => 'SUB',
            in_array($opcode, [0x39, 0x3B, 0x3D], true) => 'CMP',
            in_array($opcode, [0x21, 0x23, 0x25], true) => 'AND',
            in_array($opcode, [0x09, 0x0B, 0x0D], true) => 'OR',
            in_array($opcode, [0x31, 0x33, 0x35], true) => 'XOR',
            default => 'ADD',
        };

        // Determine operand direction
        $variant = $opcode & 0x07;

        $is64Bit = $cpu->rexW();
        $size = $is64Bit ? 64 : 32;

        return match ($variant) {
            0x01 => $this->processRmReg($runtime, $operation, $size),
            0x03 => $this->processRegRm($runtime, $operation, $size),
            0x05 => $this->processRaxImm($runtime, $operation, $size),
            default => ExecutionStatus::SUCCESS,
        };
    }

    /**
     * OP r/m64, r64
     */
    private function processRmReg(RuntimeInterface $runtime, string $operation, int $size): ExecutionStatus
    {
        $memory = $this->enhanceReader($runtime);
        $modRegRM = $memory->byteAsModRegRM();
        $cpu = $runtime->context()->cpu();

        // Read source from reg field
        $regCode = $modRegRM->register();
        if ($cpu->rexR()) {
            $regCode |= 0b1000;
        }
        $src = $this->readReg64($runtime, $regCode, $size);

        // Read destination from r/m field
        $dst = $this->readRm64($runtime, $memory, $modRegRM, $size);

        // Perform operation
        $result = $this->performOperation($runtime, $operation, $dst, $src, $size);

        // Write result (except for CMP)
        if ($operation !== 'CMP') {
            $this->writeRm64($runtime, $memory, $modRegRM, $result, $size);
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * OP r64, r/m64
     */
    private function processRegRm(RuntimeInterface $runtime, string $operation, int $size): ExecutionStatus
    {
        $memory = $this->enhanceReader($runtime);
        $modRegRM = $memory->byteAsModRegRM();
        $cpu = $runtime->context()->cpu();

        // Read source from r/m field
        $src = $this->readRm64($runtime, $memory, $modRegRM, $size);

        // Read destination from reg field
        $regCode = $modRegRM->register();
        if ($cpu->rexR()) {
            $regCode |= 0b1000;
        }
        $dst = $this->readReg64($runtime, $regCode, $size);

        // Perform operation
        $result = $this->performOperation($runtime, $operation, $dst, $src, $size);

        // Write result (except for CMP)
        if ($operation !== 'CMP') {
            $this->writeReg64($runtime, $regCode, $result, $size);
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * OP RAX, imm32 (sign-extended to 64-bit)
     */
    private function processRaxImm(RuntimeInterface $runtime, string $operation, int $size): ExecutionStatus
    {
        $memory = $this->enhanceReader($runtime);

        // Read RAX
        $dst = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize($size);

        // Read immediate (32-bit, sign-extended for 64-bit)
        $imm32 = $memory->int32();
        if ($size === 64) {
            $src = $this->signExtendImm32($imm32);
        } else {
            $src = $imm32 & 0xFFFFFFFF;
        }

        // Perform operation
        $result = $this->performOperation($runtime, $operation, $dst, $src, $size);

        // Write result (except for CMP)
        if ($operation !== 'CMP') {
            if ($size === 64) {
                $runtime->memoryAccessor()->writeBySize(RegisterType::EAX, $result, 64);
            } else {
                // 32-bit write zero-extends
                $runtime->memoryAccessor()->writeBySize(RegisterType::EAX, $result & 0xFFFFFFFF, 64);
            }
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * Perform arithmetic/logical operation and set flags.
     */
    private function performOperation(RuntimeInterface $runtime, string $operation, int $dst, int $src, int $size): int
    {
        $mask = $size === 64 ? 0xFFFFFFFFFFFFFFFF : 0xFFFFFFFF;
        $signBit = $size === 64 ? 0x8000000000000000 : 0x80000000;

        $result = match ($operation) {
            'ADD' => ($dst + $src) & $mask,
            'SUB', 'CMP' => ($dst - $src) & $mask,
            'AND' => ($dst & $src) & $mask,
            'OR' => ($dst | $src) & $mask,
            'XOR' => ($dst ^ $src) & $mask,
            default => $dst,
        };

        // Set flags
        $memAccessor = $runtime->memoryAccessor();

        // Zero flag
        $memAccessor->shouldZeroFlag($result === 0);

        // Sign flag
        $memAccessor->shouldSignFlag(($result & $signBit) !== 0);

        // Parity flag (based on low 8 bits)
        $lowByte = $result & 0xFF;
        $ones = 0;
        for ($i = 0; $i < 8; $i++) {
            if (($lowByte >> $i) & 1) {
                $ones++;
            }
        }
        $memAccessor->shouldParityFlag(($ones % 2) === 0);

        // Carry and Overflow flags (for ADD/SUB/CMP)
        if (in_array($operation, ['ADD', 'SUB', 'CMP'], true)) {
            if ($operation === 'ADD') {
                // Carry: result < dst (unsigned overflow)
                $memAccessor->shouldCarryFlag($result < ($dst & $mask));

                // Overflow: sign of result differs from expected
                $dstSign = ($dst & $signBit) !== 0;
                $srcSign = ($src & $signBit) !== 0;
                $resSign = ($result & $signBit) !== 0;
                $overflow = ($dstSign === $srcSign) && ($resSign !== $dstSign);
                $memAccessor->shouldOverflowFlag($overflow);
            } else {
                // SUB/CMP: Carry if borrow occurred (dst < src unsigned)
                $memAccessor->shouldCarryFlag(($dst & $mask) < ($src & $mask));

                // Overflow: sign changed unexpectedly
                $dstSign = ($dst & $signBit) !== 0;
                $srcSign = ($src & $signBit) !== 0;
                $resSign = ($result & $signBit) !== 0;
                $overflow = ($dstSign !== $srcSign) && ($resSign === $srcSign);
                $memAccessor->shouldOverflowFlag($overflow);
            }
        } else {
            // Logical operations clear CF and OF
            $memAccessor->shouldCarryFlag(false);
            $memAccessor->shouldOverflowFlag(false);
        }

        return $result;
    }

    /**
     * Read from register with 64-bit support.
     */
    private function readReg64(RuntimeInterface $runtime, int $regCode, int $size): int
    {
        $regType = $this->getRegisterType64($regCode);
        return $runtime->memoryAccessor()->fetch($regType)->asBytesBySize($size);
    }

    /**
     * Write to register with 64-bit support.
     */
    private function writeReg64(RuntimeInterface $runtime, int $regCode, int $value, int $size): void
    {
        $regType = $this->getRegisterType64($regCode);
        if ($size === 64) {
            $runtime->memoryAccessor()->writeBySize($regType, $value, 64);
        } else {
            // 32-bit write zero-extends to 64-bit
            $runtime->memoryAccessor()->writeBySize($regType, $value & 0xFFFFFFFF, 64);
        }
    }

    /**
     * Sign-extend 32-bit immediate to 64-bit.
     */
    private function signExtendImm32(int $value): int
    {
        if (($value & 0x80000000) !== 0) {
            return $value | 0xFFFFFFFF00000000;
        }
        return $value & 0xFFFFFFFF;
    }
}
