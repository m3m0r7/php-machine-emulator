<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class MovImm8 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return array_keys($this->registersAndOPCodes());
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $register = $this->registersAndOPCodes()[$opcode];

        if ($opcode >= 0xB8) {
            // NOTE: move instruction for Xx registers
            $runtime
                ->memoryAccessor()
                ->enableUpdateFlags(false)
                ->write16Bit(
                    $register,
                    $enhancedStreamReader
                        ->short(),
                );

            return ExecutionStatus::SUCCESS;
        }

        if ($opcode >= 0xB4) {
            // NOTE: move instruction for high-bit
            $runtime
                ->memoryAccessor()
                ->enableUpdateFlags(false)
                ->writeToHighBit(
                    $register,
                    $enhancedStreamReader
                        ->streamReader()
                        ->byte(),
                );

            return ExecutionStatus::SUCCESS;
        }

        // NOTE: move instruction for low-bit
        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->writeToLowBit(
                $register,
                $enhancedStreamReader
                    ->streamReader()
                    ->byte(),
            );

        return ExecutionStatus::SUCCESS;
    }

    private function registersAndOPCodes(): array
    {
        return [
            0xB0 + ($this->instructionList->register())::addressBy(RegisterType::EAX) => RegisterType::EAX,
            0xB0 + ($this->instructionList->register())::addressBy(RegisterType::ECX) => RegisterType::ECX,
            0xB0 + ($this->instructionList->register())::addressBy(RegisterType::EDX) => RegisterType::EDX,
            0xB0 + ($this->instructionList->register())::addressBy(RegisterType::EBX) => RegisterType::EBX,

            0xB4 + ($this->instructionList->register())::addressBy(RegisterType::EAX) => RegisterType::EAX,
            0xB4 + ($this->instructionList->register())::addressBy(RegisterType::ECX) => RegisterType::ECX,
            0xB4 + ($this->instructionList->register())::addressBy(RegisterType::EDX) => RegisterType::EDX,
            0xB4 + ($this->instructionList->register())::addressBy(RegisterType::EBX) => RegisterType::EBX,

            0xB8 + ($this->instructionList->register())::addressBy(RegisterType::EAX) => RegisterType::EAX,
            0xB8 + ($this->instructionList->register())::addressBy(RegisterType::ECX) => RegisterType::ECX,
            0xB8 + ($this->instructionList->register())::addressBy(RegisterType::EDX) => RegisterType::EDX,
            0xB8 + ($this->instructionList->register())::addressBy(RegisterType::EBX) => RegisterType::EBX,
        ];
    }
}
