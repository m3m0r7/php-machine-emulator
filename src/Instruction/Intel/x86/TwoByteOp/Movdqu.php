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
 * MOVDQU (0xF3 0x0F 0x6F /r, 0xF3 0x0F 0x7F /r)
 * Move unaligned packed integer values.
 */
class Movdqu implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes(
            [
                [0xF3, 0x0F, 0x6F], // xmm, xmm/m128
                [0xF3, 0x0F, 0x7F], // xmm/m128, xmm
            ],
            [PrefixClass::Address, PrefixClass::Segment, PrefixClass::Lock],
        );
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);

        // 0xF3 is a mandatory prefix for this instruction; do not treat it as REP.
        if (($opcodes[0] ?? null) === 0xF3) {
            $opcodes = array_slice($opcodes, 1);
        }
        $opcode = $opcodes[array_key_last($opcodes)];
        $secondByte = $opcode & 0xFF;

        $cpu = $runtime->context()->cpu();
        $memory = $runtime->memory();
        $modrmByte = $memory->byte();
        $modrm = $memory->modRegRM($modrmByte);
        $mod = ModType::from($modrm->mode());

        $rexR = $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexR();
        $rexB = $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexB();

        $regIndex = ($modrm->registerOrOPCode() & 0x7) | ($rexR ? 8 : 0);

        if ($secondByte === 0x6F) {
            // xmm, xmm/m128 (load)
            if ($mod === ModType::REGISTER_TO_REGISTER) {
                $rmIndex = ($modrm->registerOrMemoryAddress() & 0x7) | ($rexB ? 8 : 0);
                $cpu->setXmm($regIndex, $cpu->getXmm($rmIndex));
                return ExecutionStatus::SUCCESS;
            }

            $address = $this->rmLinearAddress($runtime, $memory, $modrm);
            $cpu->setXmm($regIndex, $this->readM128($runtime, $address));
            return ExecutionStatus::SUCCESS;
        }

        // xmm/m128, xmm (store)
        if ($mod === ModType::REGISTER_TO_REGISTER) {
            $rmIndex = ($modrm->registerOrMemoryAddress() & 0x7) | ($rexB ? 8 : 0);
            $cpu->setXmm($rmIndex, $cpu->getXmm($regIndex));
            return ExecutionStatus::SUCCESS;
        }

        $address = $this->rmLinearAddress($runtime, $memory, $modrm);
        $this->writeM128($runtime, $address, $cpu->getXmm($regIndex));
        return ExecutionStatus::SUCCESS;
    }

    /**
     * @return array{int,int,int,int}
     */
    private function readM128(RuntimeInterface $runtime, int $address): array
    {
        return [
            $this->readMemory32($runtime, $address) & 0xFFFFFFFF,
            $this->readMemory32($runtime, $address + 4) & 0xFFFFFFFF,
            $this->readMemory32($runtime, $address + 8) & 0xFFFFFFFF,
            $this->readMemory32($runtime, $address + 12) & 0xFFFFFFFF,
        ];
    }

    /**
     * @param array{int,int,int,int} $value
     */
    private function writeM128(RuntimeInterface $runtime, int $address, array $value): void
    {
        $this->writeMemory32($runtime, $address, $value[0] & 0xFFFFFFFF);
        $this->writeMemory32($runtime, $address + 4, $value[1] & 0xFFFFFFFF);
        $this->writeMemory32($runtime, $address + 8, $value[2] & 0xFFFFFFFF);
        $this->writeMemory32($runtime, $address + 12, $value[3] & 0xFFFFFFFF);
    }
}
