<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\OperationNotFoundException;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\BitwiseShift;
use PHPMachineEmulator\Instruction\Intel\x86\Call;
use PHPMachineEmulator\Instruction\Intel\x86\Cli;
use PHPMachineEmulator\Instruction\Intel\x86\CmpivAX;
use PHPMachineEmulator\Instruction\Intel\x86\Hlt;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Instruction\Intel\x86\Jc;
use PHPMachineEmulator\Instruction\Intel\x86\Jmp;
use PHPMachineEmulator\Instruction\Intel\x86\JmpShort;
use PHPMachineEmulator\Instruction\Intel\x86\Jnz;
use PHPMachineEmulator\Instruction\Intel\x86\Jz;
use PHPMachineEmulator\Instruction\Intel\x86\Lodsb;
use PHPMachineEmulator\Instruction\Intel\x86\LogicIns;
use PHPMachineEmulator\Instruction\Intel\x86\Loop;
use PHPMachineEmulator\Instruction\Intel\x86\MovMemoryAddress;
use PHPMachineEmulator\Instruction\Intel\x86\Moviv;
use PHPMachineEmulator\Instruction\Intel\x86\Movsg;
use PHPMachineEmulator\Instruction\Intel\x86\Movsp;
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
use PHPMachineEmulator\Video\VideoColorType;
use PHPMachineEmulator\Video\VideoTypeInfo;

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
            BitwiseShift::class,
            Call::class,
            Cli::class,
            CmpivAX::class,
            Hlt::class,
            Int_::class,
            Jc::class,
            Jmp::class,
            JmpShort::class,
            Jnz::class,
            Jz::class,
            Lodsb::class,
            LogicIns::class,
            Loop::class,
            MovMemoryAddress::class,
            Moviv::class,
            Movsg::class,
            Movsp::class,
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
