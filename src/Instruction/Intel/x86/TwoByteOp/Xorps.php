<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * XORPS/XORPD (0x0F 0x57 /r, 0x66 0x0F 0x57 /r)
 * Bitwise XOR of packed values in XMM registers.
 */
class Xorps implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([[0x0F, 0x57]]);
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

        $dstIndex = ($modrm->registerOrOPCode() & 0x7) | ($rexR ? 8 : 0);
        $dst = $cpu->getXmm($dstIndex);

        if ($mod === ModType::REGISTER_TO_REGISTER) {
            $srcIndex = ($modrm->registerOrMemoryAddress() & 0x7) | ($rexB ? 8 : 0);
            $src = $cpu->getXmm($srcIndex);
        } else {
            $address = $this->rmLinearAddress($runtime, $memory, $modrm);
            $src = $this->readM128($runtime, $address);
        }

        $cpu->setXmm($dstIndex, [
            ($dst[0] ^ $src[0]) & 0xFFFFFFFF,
            ($dst[1] ^ $src[1]) & 0xFFFFFFFF,
            ($dst[2] ^ $src[2]) & 0xFFFFFFFF,
            ($dst[3] ^ $src[3]) & 0xFFFFFFFF,
        ]);

        return ExecutionStatus::SUCCESS;
    }

    /**
     * Read 128-bit value from memory as 4x32-bit dwords.
     *
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
