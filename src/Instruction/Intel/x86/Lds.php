<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
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
        return [0xC4, 0xC5];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $reader->byteAsModRegRM();

        if (ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException('LDS/LES does not support register-direct addressing');
        }

        // Get the memory address
        [$address] = $this->effectiveAddressInfo($runtime, $reader, $modRegRM);

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
        $ma->enableUpdateFlags(false)
            ->writeBySize($modRegRM->registerOrOPCode(), $offset, $size);

        // Write segment to segment register
        $ma->enableUpdateFlags(false)
            ->write16Bit(
                ($runtime->register())::addressBy($segmentReg),
                $segment,
            );

        return ExecutionStatus::SUCCESS;
    }
}
