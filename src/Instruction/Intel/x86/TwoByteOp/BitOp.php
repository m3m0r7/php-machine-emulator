<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Bit test operations (0x0F 0xA3, 0xAB, 0xB3, 0xBA, 0xBB)
 * BT, BTS, BTR, BTC
 */
class BitOp implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([
            [0x0F, 0xA3], // BT r/m, r
            [0x0F, 0xAB], // BTS r/m, r
            [0x0F, 0xB3], // BTR r/m, r
            [0x0F, 0xBA], // BT/BTS/BTR/BTC r/m, imm8 (group)
            [0x0F, 0xBB], // BTC r/m, r
        ]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[array_key_last($opcodes)];
        $memory = $runtime->memory();
        $modrm = $memory->byteAsModRegRM();
        $opSize = $runtime->context()->cpu()->operandSize();
        $maskBits = $opSize === 32 ? 0x1F : 0x0F;

        $isReg = ModType::from($modrm->mode()) === ModType::REGISTER_TO_REGISTER;
        $baseAddr = $isReg ? null : $this->rmLinearAddress($runtime, $memory, $modrm);

        $secondByte = $opcode & 0xFF;
        if ($opcode > 0xFF) {
            $secondByte = $opcode & 0xFF;
        }

        $isImmediate = $secondByte === 0xBA;

        if ($isImmediate) {
            $imm = $memory->byte() & 0xFF;
            $op = match ($modrm->registerOrOPCode() & 0x7) {
                0b100 => 'bt',
                0b101 => 'bts',
                0b110 => 'btr',
                0b111 => 'btc',
                default => null,
            };
            $bitIndex = $imm;
        } else {
            $op = match ($secondByte) {
                0xA3 => 'bt',
                0xAB => 'bts',
                0xB3 => 'btr',
                0xBB => 'btc',
                default => null,
            };
            $bitIndex = $this->readRegisterBySize($runtime, $modrm->registerOrOPCode(), $opSize);
        }

        if ($op === null) {
            return ExecutionStatus::SUCCESS;
        }

        $bitWithin = $bitIndex & $maskBits;

        if ($isReg) {
            $dest = $this->readRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $opSize);
            $bit = ($dest >> $bitWithin) & 0x1;
            $runtime->memoryAccessor()->setCarryFlag($bit === 1);

            $newVal = match ($op) {
                'bt' => $dest,
                'bts' => $dest | (1 << $bitWithin),
                'btr' => $dest & ~(1 << $bitWithin),
                'btc' => $dest ^ (1 << $bitWithin),
                default => $dest,
            };

            if ($op !== 'bt') {
                $this->writeRegisterBySize($runtime, $modrm->registerOrMemoryAddress(), $newVal, $opSize);
            }
            return ExecutionStatus::SUCCESS;
        }

        $elemSizeBytes = $opSize === 32 ? 4 : 2;
        $elemIndex = intdiv($bitIndex, $opSize);
        $targetAddr = $baseAddr + ($elemIndex * $elemSizeBytes);
        $value = $opSize === 32 ? $this->readMemory32($runtime, $targetAddr) : $this->readMemory16($runtime, $targetAddr);
        $bitWithin = $bitIndex % $opSize;
        $bit = ($value >> $bitWithin) & 0x1;
        $runtime->memoryAccessor()->setCarryFlag($bit === 1);

        $newVal = match ($op) {
            'bt' => $value,
            'bts' => $value | (1 << $bitWithin),
            'btr' => $value & ~(1 << $bitWithin),
            'btc' => $value ^ (1 << $bitWithin),
            default => $value,
        };

        if ($op !== 'bt') {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $targetAddr, $newVal);
            } else {
                $this->writeMemory16($runtime, $targetAddr, $newVal);
            }
        }

        return ExecutionStatus::SUCCESS;
    }
}
