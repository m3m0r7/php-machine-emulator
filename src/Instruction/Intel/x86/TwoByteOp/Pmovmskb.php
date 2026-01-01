<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * PMOVMSKB (0x66 0x0F 0xD7 /r)
 * Move byte mask from XMM to r32.
 *
 * dst = mask of MSB(bit7) of each byte in src (16 bits).
 */
class Pmovmskb implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes(
            [[0x66, 0x0F, 0xD7]],
            [PrefixClass::Address, PrefixClass::Segment, PrefixClass::Lock],
        );
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $this->parsePrefixes($runtime, $opcodes);

        $cpu = $runtime->context()->cpu();
        $memory = $runtime->memory();
        $modrmByte = $memory->byte();
        $modrm = $memory->modRegRM($modrmByte);
        $mod = ModType::from($modrm->mode());

        $rexR = $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexR();
        $rexB = $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexB();

        $dstCode = $modrm->registerOrOPCode() & 0x7;
        $dstReg = $cpu->isLongMode() && !$cpu->isCompatibilityMode()
            ? Register::findGprByCode($dstCode, $rexR)
            : $dstCode;

        if ($mod === ModType::REGISTER_TO_REGISTER) {
            $srcIndex = ($modrm->registerOrMemoryAddress() & 0x7) | ($rexB ? 8 : 0);
            $src = $cpu->getXmm($srcIndex);
        } else {
            $address = $this->rmLinearAddress($runtime, $memory, $modrm);
            $src = $this->readM128($runtime, $address);
        }

        $mask = 0;
        for ($i = 0; $i < 16; $i++) {
            $dwordIndex = intdiv($i, 4);
            $shift = ($i % 4) * 8;
            $byte = ($src[$dwordIndex] >> $shift) & 0xFF;
            $mask |= (($byte >> 7) & 0x1) << $i;
        }

        $this->writeRegisterBySize($runtime, $dstReg, $mask & 0xFFFF, 32);

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
}
