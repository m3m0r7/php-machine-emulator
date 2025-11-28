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
use PHPMachineEmulator\Instruction\Intel\x86\FpuStub;
use PHPMachineEmulator\Instruction\Intel\x86\Group1;
use PHPMachineEmulator\Instruction\Intel\x86\Group2;
use PHPMachineEmulator\Instruction\Intel\x86\Group3;
use PHPMachineEmulator\Instruction\Intel\x86\Hlt;
use PHPMachineEmulator\Instruction\Intel\x86\Inc;
use PHPMachineEmulator\Instruction\Intel\x86\ImulImmediate;
use PHPMachineEmulator\Instruction\Intel\x86\Ins;
use PHPMachineEmulator\Instruction\Intel\x86\Int_;
use PHPMachineEmulator\Instruction\Intel\x86\Int3;
use PHPMachineEmulator\Instruction\Intel\x86\Jbe;
use PHPMachineEmulator\Instruction\Intel\x86\Jc;
use PHPMachineEmulator\Instruction\Intel\x86\Jnc;
use PHPMachineEmulator\Instruction\Intel\x86\Jno;
use PHPMachineEmulator\Instruction\Intel\x86\Jmp;
use PHPMachineEmulator\Instruction\Intel\x86\JmpShort;
use PHPMachineEmulator\Instruction\Intel\x86\Jnz;
use PHPMachineEmulator\Instruction\Intel\x86\Jz;
use PHPMachineEmulator\Instruction\Intel\x86\Jg;
use PHPMachineEmulator\Instruction\Intel\x86\Jl;
use PHPMachineEmulator\Instruction\Intel\x86\Lodsb;
use PHPMachineEmulator\Instruction\Intel\x86\Lodsw;
use PHPMachineEmulator\Instruction\Intel\x86\Loop;
use PHPMachineEmulator\Instruction\Intel\x86\Mov;
use PHPMachineEmulator\Instruction\Intel\x86\MovImm8;
use PHPMachineEmulator\Instruction\Intel\x86\MovMem;
use PHPMachineEmulator\Instruction\Intel\x86\MovFrom8BitReg;
use PHPMachineEmulator\Instruction\Intel\x86\Movsg;
use PHPMachineEmulator\Instruction\Intel\x86\Movsx;
use PHPMachineEmulator\Instruction\Intel\x86\MovRm16;
use PHPMachineEmulator\Instruction\Intel\x86\MovImmToRm;
use PHPMachineEmulator\Instruction\Intel\x86\MovMoffset;
use PHPMachineEmulator\Instruction\Intel\x86\Lea;
use PHPMachineEmulator\Instruction\Intel\x86\Lds;
use PHPMachineEmulator\Instruction\Intel\x86\Xchg;
use PHPMachineEmulator\Instruction\Intel\x86\Group5;
use PHPMachineEmulator\Instruction\Intel\x86\PopRm;
use PHPMachineEmulator\Instruction\Intel\x86\MovSegToRm;
use PHPMachineEmulator\Instruction\Intel\x86\MovRmToSeg;
use PHPMachineEmulator\Instruction\Intel\x86\PushSeg;
use PHPMachineEmulator\Instruction\Intel\x86\PopSeg;
use PHPMachineEmulator\Instruction\Intel\x86\Clc;
use PHPMachineEmulator\Instruction\Intel\x86\Stc;
use PHPMachineEmulator\Instruction\Intel\x86\Cmc;
use PHPMachineEmulator\Instruction\Intel\x86\Pusha;
use PHPMachineEmulator\Instruction\Intel\x86\Popa;
use PHPMachineEmulator\Instruction\Intel\x86\PushImm;
use PHPMachineEmulator\Instruction\Intel\x86\Pushf;
use PHPMachineEmulator\Instruction\Intel\x86\Popf;
use PHPMachineEmulator\Instruction\Intel\x86\Lahf;
use PHPMachineEmulator\Instruction\Intel\x86\Sahf;
use PHPMachineEmulator\Instruction\Intel\x86\CallFar;
use PHPMachineEmulator\Instruction\Intel\x86\JmpFar;
use PHPMachineEmulator\Instruction\Intel\x86\Iret;
use PHPMachineEmulator\Instruction\Intel\x86\Cld;
use PHPMachineEmulator\Instruction\Intel\x86\Std;
use PHPMachineEmulator\Instruction\Intel\x86\Movsb;
use PHPMachineEmulator\Instruction\Intel\x86\Movsw;
use PHPMachineEmulator\Instruction\Intel\x86\Cmpsb;
use PHPMachineEmulator\Instruction\Intel\x86\Cmpsw;
use PHPMachineEmulator\Instruction\Intel\x86\Scasb;
use PHPMachineEmulator\Instruction\Intel\x86\Scasw;
use PHPMachineEmulator\Instruction\Intel\x86\RepPrefix;
use PHPMachineEmulator\Instruction\Intel\x86\AddRegRm;
use PHPMachineEmulator\Instruction\Intel\x86\SubRegRm;
use PHPMachineEmulator\Instruction\Intel\x86\CmpRegRm;
use PHPMachineEmulator\Instruction\Intel\x86\Stosw;
use PHPMachineEmulator\Instruction\Intel\x86\OrRegRm;
use PHPMachineEmulator\Instruction\Intel\x86\AndRegRm;
use PHPMachineEmulator\Instruction\Intel\x86\XorRegRm;
use PHPMachineEmulator\Instruction\Intel\x86\TestRegRm;
use PHPMachineEmulator\Instruction\Intel\x86\AdcRegRm;
use PHPMachineEmulator\Instruction\Intel\x86\SbbRegRm;
use PHPMachineEmulator\Instruction\Intel\x86\HltPorts;
use PHPMachineEmulator\Instruction\Intel\x86\Group4;
use PHPMachineEmulator\Instruction\Intel\x86\OperandSizePrefix;
use PHPMachineEmulator\Instruction\Intel\x86\AddressSizePrefix;
use PHPMachineEmulator\Instruction\Intel\x86\SegmentOverridePrefix;
use PHPMachineEmulator\Instruction\Intel\x86\LockPrefix;
use PHPMachineEmulator\Instruction\Intel\x86\TwoBytePrefix;
use PHPMachineEmulator\Instruction\Intel\x86\CbwCwd;
use PHPMachineEmulator\Instruction\Intel\x86\Nop;
use PHPMachineEmulator\Instruction\Intel\x86\In_;
use PHPMachineEmulator\Instruction\Intel\x86\Or_;
use PHPMachineEmulator\Instruction\Intel\x86\Out_;
use PHPMachineEmulator\Instruction\Intel\x86\Outs;
use PHPMachineEmulator\Instruction\Intel\x86\Bound;
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
            Int3::class,
            Jbe::class,
            Jc::class,
            Jnc::class,
            Jno::class,
            Jmp::class,
            JmpShort::class,
            Jnz::class,
            Jz::class,
            Jg::class,
            Jl::class,
            Lodsb::class,
            Lodsw::class,
            Loop::class,
            In_::class,
            Mov::class,
            MovImm8::class,
            MovMem::class,
            MovRm16::class,
            MovFrom8BitReg::class,
            MovImmToRm::class,
            MovMoffset::class,
            Lea::class,
            Lds::class,
            Xchg::class,
            Movsg::class,
            Movsx::class,
            Nop::class,
            Or_::class,
            Out_::class,
            PopReg::class,
            PopRm::class,
            MovSegToRm::class,
            MovRmToSeg::class,
            PushSeg::class,
            PopSeg::class,
            Clc::class,
            Stc::class,
            Cmc::class,
            Pusha::class,
            Popa::class,
            PushImm::class,
            Pushf::class,
            Popf::class,
            Lahf::class,
            Sahf::class,
            CallFar::class,
            JmpFar::class,
            Iret::class,
            Cld::class,
            Std::class,
            Movsb::class,
            Movsw::class,
            Cmpsb::class,
            Cmpsw::class,
            Scasb::class,
            Scasw::class,
            Ins::class,
            Outs::class,
            Bound::class,
            RepPrefix::class,
            AddRegRm::class,
            SubRegRm::class,
            CmpRegRm::class,
            OrRegRm::class,
            AndRegRm::class,
            XorRegRm::class,
            TestRegRm::class,
            AdcRegRm::class,
            SbbRegRm::class,
            HltPorts::class,
            Group4::class,
            OperandSizePrefix::class,
            AddressSizePrefix::class,
            SegmentOverridePrefix::class,
            LockPrefix::class,
            TwoBytePrefix::class,
            CbwCwd::class,
            PushReg::class,
            Group5::class,
            Ret::class,
            Sti::class,
            Stosb::class,
            Stosw::class,
            Xor_::class,
            ImulImmediate::class,
            FpuStub::class,
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

            // Allow class-name lookup for auxiliary dispatch (e.g., faults calling INT).
            $this->instructionList[$className] = $instance;
        }

        return $this->instructionList;
    }
}
