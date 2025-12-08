<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel;

use PHPMachineEmulator\Exception\InvalidOpcodeException;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\AddImm8;
use PHPMachineEmulator\Instruction\Intel\x86\AndImm8;
use PHPMachineEmulator\Instruction\Intel\x86\Arpl;
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
use PHPMachineEmulator\Instruction\Intel\x86\Int1;
use PHPMachineEmulator\Instruction\Intel\x86\Int3;
use PHPMachineEmulator\Instruction\Intel\x86\Into;
use PHPMachineEmulator\Instruction\Intel\x86\Ja;
use PHPMachineEmulator\Instruction\Intel\x86\Jbe;
use PHPMachineEmulator\Instruction\Intel\x86\Jc;
use PHPMachineEmulator\Instruction\Intel\x86\Jcxz;
use PHPMachineEmulator\Instruction\Intel\x86\Jnc;
use PHPMachineEmulator\Instruction\Intel\x86\Jo;
use PHPMachineEmulator\Instruction\Intel\x86\Jno;
use PHPMachineEmulator\Instruction\Intel\x86\Js;
use PHPMachineEmulator\Instruction\Intel\x86\Jns;
use PHPMachineEmulator\Instruction\Intel\x86\Jp;
use PHPMachineEmulator\Instruction\Intel\x86\Jnp;
use PHPMachineEmulator\Instruction\Intel\x86\Jmp;
use PHPMachineEmulator\Instruction\Intel\x86\JmpShort;
use PHPMachineEmulator\Instruction\Intel\x86\Jnz;
use PHPMachineEmulator\Instruction\Intel\x86\Jz;
use PHPMachineEmulator\Instruction\Intel\x86\Jg;
use PHPMachineEmulator\Instruction\Intel\x86\Jge;
use PHPMachineEmulator\Instruction\Intel\x86\Jl;
use PHPMachineEmulator\Instruction\Intel\x86\Jle;
use PHPMachineEmulator\Instruction\Intel\x86\Lodsb;
use PHPMachineEmulator\Instruction\Intel\x86\Lodsw;
use PHPMachineEmulator\Instruction\Intel\x86\Loop;
use PHPMachineEmulator\Instruction\Intel\x86\Loopne;
use PHPMachineEmulator\Instruction\Intel\x86\Loopz;
use PHPMachineEmulator\Instruction\Intel\x86\Mov;
use PHPMachineEmulator\Instruction\Intel\x86\MovImm8;
use PHPMachineEmulator\Instruction\Intel\x86\MovMem;
use PHPMachineEmulator\Instruction\Intel\x86\MovFrom8BitReg;
use PHPMachineEmulator\Instruction\Intel\x86\Movsg;
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
use PHPMachineEmulator\Instruction\Intel\x86\Salc;
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
use PHPMachineEmulator\Instruction\Intel\x86\CbwCwd;
use PHPMachineEmulator\Instruction\Intel\x86\Aaa;
use PHPMachineEmulator\Instruction\Intel\x86\Aad;
use PHPMachineEmulator\Instruction\Intel\x86\Aam;
use PHPMachineEmulator\Instruction\Intel\x86\Aas;
use PHPMachineEmulator\Instruction\Intel\x86\Daa;
use PHPMachineEmulator\Instruction\Intel\x86\Das;
use PHPMachineEmulator\Instruction\Intel\x86\Enter;
use PHPMachineEmulator\Instruction\Intel\x86\Leave;
use PHPMachineEmulator\Instruction\Intel\x86\Nop;
use PHPMachineEmulator\Instruction\Intel\x86\In_;
use PHPMachineEmulator\Instruction\Intel\x86\Out_;
use PHPMachineEmulator\Instruction\Intel\x86\Outs;
use PHPMachineEmulator\Instruction\Intel\x86\Bound;
use PHPMachineEmulator\Instruction\Intel\x86\PopReg;
use PHPMachineEmulator\Instruction\Intel\x86\PushReg;
use PHPMachineEmulator\Instruction\Intel\x86\Ret;
use PHPMachineEmulator\Instruction\Intel\x86\Sti;
use PHPMachineEmulator\Instruction\Intel\x86\Stosb;
use PHPMachineEmulator\Instruction\Intel\x86\TestImmAl;
use PHPMachineEmulator\Instruction\Intel\x86\Xlat;
use PHPMachineEmulator\Instruction\Intel\x86\Xor_;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\BitOp;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Bsf;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Bsr;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Bswap;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\CacheOp;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Clts;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Cmovcc;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Cmpxchg;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Cmpxchg8b;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Cpuid;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Emms;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Fxsave;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Group0;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Group6;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\ImulRegRm;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\JccNear;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Lxs;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\MovFromCr;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\MovToCr;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Movsx;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Movzx;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\NopModrm;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\PopFsGs;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\PushFsGs;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Rdmsr;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Rdtsc;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Setcc;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Shld;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Shrd;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Sysenter;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Sysexit;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Ud2;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Wrmsr;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Xadd;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\Instruction\Traits\RuntimeAwareTrait;

/**
 * Standard x86 instruction list.
 *
 * This class provides the base x86 instruction set. For extended instruction
 * sets with custom PHP emulator instructions, use x86Extended instead.
 */
class x86 implements InstructionListInterface
{
    use RuntimeAwareTrait;

    protected ?RegisterInterface $register = null;
    protected array $instructionList = [];

    /**
     * Multi-byte opcode map: [length][byte1][byte2]... => [instruction, opcodeKey]
     * @var array<int, array>
     */
    protected array $multiByteOpcodes = [];

    protected int $maxOpcodeLength = 1;

    public function register(): RegisterInterface
    {
        return $this->register ??= new Register();
    }

    public function findInstruction(int|array $opcodes): array
    {
        $this->instructionList(); // ensure initialized

        $opcodeArray = is_array($opcodes) ? $opcodes : [$opcodes];
        $length = count($opcodeArray);

        // Try multi-byte match first (longest to shortest)
        if ($length >= 2) {
            for ($len = min($length, $this->maxOpcodeLength); $len >= 2; $len--) {
                $key = $this->bytesToKey(array_slice($opcodeArray, 0, $len));
                if (isset($this->multiByteOpcodes[$key])) {
                    return $this->multiByteOpcodes[$key];
                }
            }
        }

        // Fall back to single-byte lookup
        $opcode = $opcodeArray[0];
        $instruction = $this->instructionList[$opcode] ?? throw new InvalidOpcodeException($opcode);

        return [$instruction, $opcode];
    }

    public function isMultiByteOpcode(array $bytes): bool
    {
        $this->instructionList(); // ensure initialized

        $length = count($bytes);
        if ($length < 2) {
            return false;
        }

        for ($len = min($length, $this->maxOpcodeLength); $len >= 2; $len--) {
            $key = $this->bytesToKey(array_slice($bytes, 0, $len));
            if (isset($this->multiByteOpcodes[$key])) {
                return true;
            }
        }

        return false;
    }

    public function getMaxOpcodeLength(): int
    {
        $this->instructionList(); // ensure initialized
        return $this->maxOpcodeLength;
    }

    /**
     * Convert a byte array to a unique key for indexing.
     * Example: [0x0F, 0x20] => 0x0F20
     *
     * @param int[] $bytes
     * @return int
     */
    protected function bytesToKey(array $bytes): int
    {
        $key = 0;
        foreach ($bytes as $byte) {
            $key = ($key << 8) | ($byte & 0xFF);
        }
        return $key;
    }

    protected function instructionListClasses(): array
    {
        return [
            AddImm8::class,
            Call::class,
            Cli::class,
            CmpImmAX::class,
            Dec::class,
            Group1::class,
            Group2::class,
            Group3::class,
            Arpl::class,
            Hlt::class,
            Inc::class,
            Int_::class,
            Int1::class,
            Int3::class,
            Into::class,
            Ja::class,
            Jbe::class,
            Jc::class,
            Jcxz::class,
            Jnc::class,
            Jo::class,
            Jno::class,
            Js::class,
            Jns::class,
            Jp::class,
            Jnp::class,
            Jmp::class,
            JmpShort::class,
            Jnz::class,
            Jz::class,
            Jg::class,
            Jge::class,
            Jl::class,
            Jle::class,
            Lodsb::class,
            Lodsw::class,
            Loop::class,
            Loopne::class,
            Loopz::class,
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
            Nop::class,
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
            Salc::class,
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
            CbwCwd::class,
            Aaa::class,
            Aad::class,
            Aam::class,
            Aas::class,
            Daa::class,
            Das::class,
            Enter::class,
            Leave::class,
            PushReg::class,
            Group5::class,
            Ret::class,
            Sti::class,
            Stosb::class,
            Stosw::class,
            TestImmAl::class,
            Xlat::class,
            Xor_::class,
            ImulImmediate::class,
            FpuStub::class,
            // Two-byte instructions (0x0F prefix)
            MovFromCr::class,
            MovToCr::class,
            Cpuid::class,
            Rdtsc::class,
            Movzx::class,
            Movsx::class,
            JccNear::class,
            Setcc::class,
            Cmovcc::class,
            Bswap::class,
            Cmpxchg::class,
            Xadd::class,
            ImulRegRm::class,
            Shld::class,
            Shrd::class,
            Bsf::class,
            Bsr::class,
            BitOp::class,
            PushFsGs::class,
            PopFsGs::class,
            Lxs::class,
            Clts::class,
            Rdmsr::class,
            Wrmsr::class,
            Sysenter::class,
            Sysexit::class,
            Cmpxchg8b::class,
            Group0::class,
            Group6::class,
            NopModrm::class,
            Ud2::class,
            CacheOp::class,
            Emms::class,
            Fxsave::class,
        ];
    }

    public function instructionList(): array
    {
        if (!empty($this->instructionList)) {
            return $this->instructionList;
        }

        foreach ($this->instructionListClasses() as $className) {
            $instance = new $className($this);
            assert($instance instanceof InstructionInterface);

            foreach ($instance->opcodes() as $opcode) {
                if (is_array($opcode)) {
                    // Multi-byte opcode
                    $length = count($opcode);
                    if ($length > $this->maxOpcodeLength) {
                        $this->maxOpcodeLength = $length;
                    }
                    $key = $this->bytesToKey($opcode);
                    $this->multiByteOpcodes[$key] = [$instance, $key];
                    // Also register by first byte for fallback lookup
                    $this->instructionList[$opcode[0]] ??= $instance;
                } else {
                    // Single-byte opcode
                    $this->instructionList[$opcode] = $instance;
                }
            }

            // Allow class-name lookup for auxiliary dispatch (e.g., faults calling INT).
            $this->instructionList[$className] = $instance;
        }

        return $this->instructionList;
    }
}
