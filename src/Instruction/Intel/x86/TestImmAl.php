<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

/**
 * TEST AL, imm8 (0xA8) - Test AL with immediate byte
 * TEST AX/EAX, imm16/32 (0xA9) - Test AX/EAX with immediate word/dword
 */
class TestImmAl implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xA8, 0xA9]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        if ($opcode === 0xA8) {
            // TEST AL, imm8
            $al = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
            $imm = $runtime->memory()->byte();
            $result = $al & $imm;
            $runtime->memoryAccessor()
                ->updateFlags($result, 8)
                ->setCarryFlag(false)
                ->setOverflowFlag(false)
                ->setAuxiliaryCarryFlag(false);
        } else {
            // TEST AX/EAX, imm16/32
            $opSize = $runtime->context()->cpu()->operandSize();
            if ($opSize === 64) {
                $ma = $runtime->memoryAccessor();
                $rax = $ma->fetch(RegisterType::EAX)->asBytesBySize(64);
                $imm32 = $runtime->memory()->dword();
                $imm64 = ($imm32 & 0x80000000) !== 0
                    ? ($imm32 | 0xFFFFFFFF00000000)
                    : ($imm32 & 0xFFFFFFFF);

                $resultU = UInt64::of($rax)->and(UInt64::of($imm64));

                $ma->setZeroFlag($resultU->isZero());
                $ma->setSignFlag($resultU->isNegativeSigned());
                $lowByte = $resultU->low32() & 0xFF;
                $ones = 0;
                for ($i = 0; $i < 8; $i++) {
                    $ones += ($lowByte >> $i) & 1;
                }
                $ma->setParityFlag(($ones % 2) === 0);
                $ma->setCarryFlag(false);
                $ma->setOverflowFlag(false);
                $ma->setAuxiliaryCarryFlag(false);

                return ExecutionStatus::SUCCESS;
            }
            if ($opSize === 32) {
                $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize(32);
                $imm = $runtime->memory()->dword();
                $result = ($ax & $imm) & 0xFFFFFFFF;
            } else {
                $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize(16);
                $imm = $runtime->memory()->short();
                $result = ($ax & $imm) & 0xFFFF;
            }
            $runtime->memoryAccessor()
                ->updateFlags($result, $opSize)
                ->setCarryFlag(false)
                ->setOverflowFlag(false)
                ->setAuxiliaryCarryFlag(false);
        }

        return ExecutionStatus::SUCCESS;
    }
}
