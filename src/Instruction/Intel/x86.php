<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\OperationNotFoundException;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\AddImm8;
use PHPMachineEmulator\Instruction\Intel\x86\AndImm8;
use PHPMachineEmulator\Instruction\Intel\x86\Call;
use PHPMachineEmulator\Instruction\Intel\x86\Cli;
use PHPMachineEmulator\Instruction\Intel\x86\CmpImmAX;
use PHPMachineEmulator\Instruction\Intel\x86\Dec;
use PHPMachineEmulator\Instruction\Intel\x86\Group1;
use PHPMachineEmulator\Instruction\Intel\x86\Group2;
use PHPMachineEmulator\Instruction\Intel\x86\Group3;
use PHPMachineEmulator\Instruction\Intel\x86\Hlt;
use PHPMachineEmulator\Instruction\Intel\x86\Inc;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Instruction\Intel\x86\Jbe;
use PHPMachineEmulator\Instruction\Intel\x86\Jc;
use PHPMachineEmulator\Instruction\Intel\x86\Jmp;
use PHPMachineEmulator\Instruction\Intel\x86\JmpShort;
use PHPMachineEmulator\Instruction\Intel\x86\Jnz;
use PHPMachineEmulator\Instruction\Intel\x86\Jz;
use PHPMachineEmulator\Instruction\Intel\x86\Lodsb;
use PHPMachineEmulator\Instruction\Intel\x86\Loop;
use PHPMachineEmulator\Instruction\Intel\x86\Mov;
use PHPMachineEmulator\Instruction\Intel\x86\MovImm8;
use PHPMachineEmulator\Instruction\Intel\x86\MovMem;
use PHPMachineEmulator\Instruction\Intel\x86\MovFrom8BitReg;
use PHPMachineEmulator\Instruction\Intel\x86\Movsg;
use PHPMachineEmulator\Instruction\Intel\x86\Movsx;
use PHPMachineEmulator\Instruction\Intel\x86\Nop;
use PHPMachineEmulator\Instruction\Intel\x86\Or_;
use PHPMachineEmulator\Instruction\Intel\x86\PopReg;
use PHPMachineEmulator\Instruction\Intel\x86\PushReg;
use PHPMachineEmulator\Instruction\Intel\x86\Ret;
use PHPMachineEmulator\Instruction\Intel\x86\Sti;
use PHPMachineEmulator\Instruction\Intel\x86\Stosb;
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
            AddImm8::class,
            AndImm8::class,
            Call::class,
            Cli::class,
            CmpImmAX::class,
            Dec::class,
            Group1::class,
            Group2::class,
            Group3::class,
            Hlt::class,
            Inc::class,
            Int_::class,
            Jbe::class,
            Jc::class,
            Jmp::class,
            JmpShort::class,
            Jnz::class,
            Jz::class,
            Lodsb::class,
            Loop::class,
            Mov::class,
            MovImm8::class,
            MovMem::class,
            MovFrom8BitReg::class,
            Movsg::class,
            Movsx::class,
            Nop::class,
            Or_::class,
            PopReg::class,
            PushReg::class,
            Ret::class,
            Sti::class,
            Stosb::class,
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
