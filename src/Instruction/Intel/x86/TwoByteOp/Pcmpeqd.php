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
 * PCMPEQD (0x66 0x0F 0x76 /r)
 * Compare packed 32-bit integers for equality.
 *
 * dst = (dst_dword == src_dword) ? 0xFFFFFFFF : 0x00000000 (for each dword lane)
 */
class Pcmpeqd implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes(
            [[0x66, 0x0F, 0x76]],
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

        $dstIndex = ($modrm->registerOrOPCode() & 0x7) | ($rexR ? 8 : 0);
        $dst = $cpu->getXmm($dstIndex);

        if ($mod === ModType::REGISTER_TO_REGISTER) {
            $srcIndex = ($modrm->registerOrMemoryAddress() & 0x7) | ($rexB ? 8 : 0);
            $src = $cpu->getXmm($srcIndex);
        } else {
            $address = $this->rmLinearAddress($runtime, $memory, $modrm);
            $src = $this->readM128($runtime, $address);
        }

        $out = [];
        for ($i = 0; $i < 4; $i++) {
            $a = $dst[$i] & 0xFFFFFFFF;
            $b = $src[$i] & 0xFFFFFFFF;
            $out[$i] = ($a === $b) ? 0xFFFFFFFF : 0x00000000;
        }

        /** @var array{int,int,int,int} $out */
        $cpu->setXmm($dstIndex, $out);

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

