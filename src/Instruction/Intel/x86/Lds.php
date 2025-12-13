<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * LDS/LES - Load Far Pointer
 *
 * LDS r16/32, m16:16/32 (0xC5) - Load DS:r with far pointer from memory
 * LES r16/32, m16:16/32 (0xC4) - Load ES:r with far pointer from memory
 */
class Lds implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xC4, 0xC5]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException('LDS/LES does not support register-direct addressing');
        }

        // Get the linear address of the far pointer operand.
        // LDS/LES use normal ModR/M addressing rules including segment overrides.
        $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);

        $size = $runtime->context()->cpu()->operandSize();
        $ma = $runtime->memoryAccessor();

        // Read offset (16 or 32 bits depending on operand size)
        $offset = $size === 32
            ? $this->readMemory32($runtime, $address)
            : $this->readMemory16($runtime, $address);

        // Read segment selector (always 16 bits, follows the offset)
        $segmentOffset = $size === 32 ? 4 : 2;
        $segment = $this->readMemory16($runtime, $address + $segmentOffset);

        // Determine target segment register
        $segmentReg = $opcode === 0xC4 ? RegisterType::ES : RegisterType::DS;

        // Write offset to destination register
        $ma->writeBySize($modRegRM->registerOrOPCode(), $offset, $size);

        // Write segment to segment register
        $ma->write16Bit(
            ($runtime->register())::addressBy($segmentReg),
            $segment,
        );

        // Maintain hidden segment cache for protected/unreal and real-mode semantics.
        $cpu = $runtime->context()->cpu();
        if ($cpu->isProtectedMode() && $segment !== 0) {
            $descriptor = $this->readSegmentDescriptor($runtime, $segment);
            if ($descriptor !== null && ($descriptor['present'] ?? false)) {
                $cpu->cacheSegmentDescriptor($segmentReg, $descriptor);
            }
        }
        if (!$cpu->isProtectedMode()) {
            $cpu->cacheSegmentDescriptor($segmentReg, [
                'base' => (($segment << 4) & 0xFFFFF),
                'limit' => 0xFFFF,
                'present' => true,
                'type' => 0,
                'system' => false,
                'executable' => false,
                'dpl' => 0,
                'default' => 16,
            ]);
        }

        return ExecutionStatus::SUCCESS;
    }
}
