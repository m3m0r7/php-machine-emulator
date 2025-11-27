<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Dec implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return array_keys($this->registersAndOPCodes());
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $size = $runtime->context()->cpu()->operandSize();
        $reg = ($this->registersAndOPCodes())[$opcode];
        $ma = $runtime->memoryAccessor();
        $value = $ma->fetch($reg)->asBytesBySize($size);
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $result = ($value - 1) & $mask;
        $ma->enableUpdateFlags(false)->writeBySize($reg, $result, $size);
        $ma->updateFlags($result, $size);

        return ExecutionStatus::SUCCESS;
    }


    private function registersAndOPCodes(): array
    {
        return [
            0x48 + ($this->instructionList->register())::addressBy(RegisterType::EAX) => RegisterType::EAX,
            0x48 + ($this->instructionList->register())::addressBy(RegisterType::ECX) => RegisterType::ECX,
            0x48 + ($this->instructionList->register())::addressBy(RegisterType::EDX) => RegisterType::EDX,
            0x48 + ($this->instructionList->register())::addressBy(RegisterType::EBX) => RegisterType::EBX,
            0x48 + ($this->instructionList->register())::addressBy(RegisterType::ESP) => RegisterType::ESP,
            0x48 + ($this->instructionList->register())::addressBy(RegisterType::EBP) => RegisterType::EBP,
            0x48 + ($this->instructionList->register())::addressBy(RegisterType::ESI) => RegisterType::ESI,
            0x48 + ($this->instructionList->register())::addressBy(RegisterType::EDI) => RegisterType::EDI,
        ];
    }
}
