<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Inc implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes(array_keys($this->registersAndOPCodes()));
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $size = $runtime->context()->cpu()->operandSize();
        $reg = ($this->registersAndOPCodes())[$opcode];
        $ma = $runtime->memoryAccessor();
        $value = $ma->fetch($reg)->asBytesBySize($size);
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = ($value + 1) & $mask;
        $af = (($value & 0x0F) + 1) > 0x0F;

        // Preserve CF - INC does not affect carry flag
        $savedCf = $ma->shouldCarryFlag();

        $ma->writeBySize($reg, $result, $size);
        $ma->updateFlags($result, $size);
        $ma->setAuxiliaryCarryFlag($af);

        // Restore CF
        $ma->setCarryFlag($savedCf);

        // OF for INC: set when result is SIGN_MASK (0x80, 0x8000, 0x80000000)
        // This happens when incrementing from max positive to min negative
        $signMask = 1 << ($size - 1);
        $ma->setOverflowFlag($result === $signMask);

        return ExecutionStatus::SUCCESS;
    }


    private function registersAndOPCodes(): array
    {
        return [
            0x40 + ($this->instructionList->register())::addressBy(RegisterType::EAX) => RegisterType::EAX,
            0x40 + ($this->instructionList->register())::addressBy(RegisterType::ECX) => RegisterType::ECX,
            0x40 + ($this->instructionList->register())::addressBy(RegisterType::EDX) => RegisterType::EDX,
            0x40 + ($this->instructionList->register())::addressBy(RegisterType::EBX) => RegisterType::EBX,
            0x40 + ($this->instructionList->register())::addressBy(RegisterType::ESP) => RegisterType::ESP,
            0x40 + ($this->instructionList->register())::addressBy(RegisterType::EBP) => RegisterType::EBP,
            0x40 + ($this->instructionList->register())::addressBy(RegisterType::ESI) => RegisterType::ESI,
            0x40 + ($this->instructionList->register())::addressBy(RegisterType::EDI) => RegisterType::EDI,
        ];
    }
}
