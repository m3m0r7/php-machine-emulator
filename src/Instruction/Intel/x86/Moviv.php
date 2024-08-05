<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Moviv implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return array_keys($this->registers());
    }

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        $operand = $runtime->streamReader()->byte();

        $register = $this->registers()[$opcode];

        if ($opcode >= 0xB8) {
            $operand2 = $runtime->streamReader()->byte();

            // NOTE: move instruction for Xx registers
            $runtime
                ->memoryAccessor()
                ->write(
                    $register,
                    ($operand2 << 8) + $operand,
                );

            return ExecutionStatus::SUCCESS;
        }

        if ($opcode >= 0xB4) {
            // NOTE: move instruction for high-bit
            $runtime
                ->memoryAccessor()
                ->write(
                    $register,
                    (($operand << 8) & 0b11111111_00000000) + ($runtime->memoryAccessor()->fetch($register)->asByte()),
                );

            return ExecutionStatus::SUCCESS;
        }

        // NOTE: move instruction for low-bit
        $runtime
            ->memoryAccessor()
            ->write(
                $register,
                (($operand << 8) & 0b11111111) + ($runtime->memoryAccessor()->fetch($register)->asByte() & 0b11111111),
            );

        return ExecutionStatus::SUCCESS;
    }

    private function registers(): array
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
