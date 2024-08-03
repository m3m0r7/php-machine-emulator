<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\OperationNotFoundException;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Call;
use PHPMachineEmulator\Instruction\Intel\x86\Cli;
use PHPMachineEmulator\Instruction\Intel\x86\Hlt;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Instruction\Intel\x86\Jmp;
use PHPMachineEmulator\Instruction\Intel\x86\Jz;
use PHPMachineEmulator\Instruction\Intel\x86\Lodsb;
use PHPMachineEmulator\Instruction\Intel\x86\Moviv;
use PHPMachineEmulator\Instruction\Intel\x86\Movsg;
use PHPMachineEmulator\Instruction\Intel\x86\Movsp;
use PHPMachineEmulator\Instruction\Intel\x86\Movsx;
use PHPMachineEmulator\Instruction\Intel\x86\Nop;
use PHPMachineEmulator\Instruction\Intel\x86\Or_;
use PHPMachineEmulator\Instruction\Intel\x86\Ret;
use PHPMachineEmulator\Instruction\Intel\x86\Sti;
use PHPMachineEmulator\Instruction\Intel\x86\Xor_;
use PHPMachineEmulator\Instruction\RegisterInterface;

class x86 implements InstructionListInterface
{
    protected ?RegisterInterface $register = null;
    protected array $instructionList = [];

    public function register(): RegisterInterface
    {
        return $this->register ??= new Register();
    }

    public function getInstructionByOperationCode(int $opcode): InstructionInterface
    {
        return $this->instructionList()[$opcode] ?? throw new OperationNotFoundException(
            sprintf('Operation not found: 0x%04X', $opcode),
        );
    }

    public function instructionList(): array
    {
        static $instructionList = [
            Call::class,
            Cli::class,
            Hlt::class,
            Int_::class,
            Jmp::class,
            Jz::class,
            Lodsb::class,
            Moviv::class,
            Movsg::class,
            Movsp::class,
            Movsx::class,
            Nop::class,
            Or_::class,
            Ret::class,
            Sti::class,
            Xor_::class,
        ];

        if (!empty($this->instructionList)) {
            return $this->instructionList;
        }

        foreach ($instructionList as $className) {
            $instance = new $className($this);
            assert($instance instanceof InstructionInterface);

            foreach ($instance->opcodes() as $opcode) {
                $this->instructionList[$opcode] = $instance;
            }
        }

        return $this->instructionList;
    }
}
