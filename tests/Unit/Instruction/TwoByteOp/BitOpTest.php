<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\BitOp;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\UInt64;

/**
 * Tests for BT, BTS, BTR, BTC instructions.
 *
 * BT r/m16/32, r16/32: 0x0F 0xA3
 * BTS r/m16/32, r16/32: 0x0F 0xAB
 * BTR r/m16/32, r16/32: 0x0F 0xB3
 * BTC r/m16/32, r16/32: 0x0F 0xBB
 */
class BitOpTest extends TwoByteOpTestCase
{
    private BitOp $bitOp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bitOp = new BitOp($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->bitOp;
    }

    // ========================================
    // BT (Bit Test) Tests - 0x0F 0xA3
    // ========================================

    public function testBtBitSet(): void
    {
        // BT EAX, ECX - test bit ECX in EAX
        // ModRM: r/m=EAX (000), reg=ECX (001) -> 11 001 000 = 0xC8
        $this->setRegister(RegisterType::EAX, 0x00000010); // Bit 4 set
        $this->setRegister(RegisterType::ECX, 4);

        $this->executeBitOp(0xA3, [0xC8]);

        $this->assertTrue($this->getCarryFlag()); // CF = bit value
    }

    public function testBtBitClear(): void
    {
        // BT EAX, ECX - test bit ECX in EAX
        // ModRM: r/m=EAX (000), reg=ECX (001) -> 11 001 000 = 0xC8
        $this->setRegister(RegisterType::EAX, 0x00000010); // Bit 4 set
        $this->setRegister(RegisterType::ECX, 3); // Test bit 3

        $this->executeBitOp(0xA3, [0xC8]);

        $this->assertFalse($this->getCarryFlag());
    }

    public function testBtHighBit(): void
    {
        // BT EAX, ECX - test bit 31
        // ModRM: r/m=EAX (000), reg=ECX (001) -> 11 001 000 = 0xC8
        $this->setRegister(RegisterType::EAX, 0x80000000);
        $this->setRegister(RegisterType::ECX, 31);

        $this->executeBitOp(0xA3, [0xC8]);

        $this->assertTrue($this->getCarryFlag());
    }

    public function testBtBitIndexModulo32(): void
    {
        // BT with bit index > 31 should be modulo 32
        // ModRM: r/m=EAX (000), reg=ECX (001) -> 11 001 000 = 0xC8
        $this->setRegister(RegisterType::EAX, 0x00000010); // Bit 4 set
        $this->setRegister(RegisterType::ECX, 36); // 36 % 32 = 4

        $this->executeBitOp(0xA3, [0xC8]);

        $this->assertTrue($this->getCarryFlag());
    }

    // ========================================
    // BTS (Bit Test and Set) Tests - 0x0F 0xAB
    // ========================================

    public function testBtsBitWasClear(): void
    {
        // BTS EAX, ECX - test and set bit ECX in EAX
        // ModRM: r/m=EAX (000), reg=ECX (001) -> 11 001 000 = 0xC8
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->setRegister(RegisterType::ECX, 4);

        $this->executeBitOp(0xAB, [0xC8]);

        $this->assertFalse($this->getCarryFlag()); // Was 0
        $this->assertSame(0x00000010, $this->getRegister(RegisterType::EAX)); // Bit 4 now set
    }

    public function testBtsBitWasSet(): void
    {
        // BTS EAX, ECX - test and set bit that was already set
        // ModRM: r/m=EAX (000), reg=ECX (001) -> 11 001 000 = 0xC8
        $this->setRegister(RegisterType::EAX, 0x00000010); // Bit 4 already set
        $this->setRegister(RegisterType::ECX, 4);

        $this->executeBitOp(0xAB, [0xC8]);

        $this->assertTrue($this->getCarryFlag()); // Was 1
        $this->assertSame(0x00000010, $this->getRegister(RegisterType::EAX)); // Still set
    }

    public function testBtsMultipleBits(): void
    {
        // Set multiple bits using BTS
        // ModRM: r/m=EAX (000), reg=ECX (001) -> 11 001 000 = 0xC8
        $this->setRegister(RegisterType::EAX, 0x00000000);

        // Set bit 0
        $this->setRegister(RegisterType::ECX, 0);
        $this->executeBitOp(0xAB, [0xC8]);

        // Set bit 7
        $this->setRegister(RegisterType::ECX, 7);
        $this->executeBitOp(0xAB, [0xC8]);

        // Set bit 31
        $this->setRegister(RegisterType::ECX, 31);
        $this->executeBitOp(0xAB, [0xC8]);

        $this->assertSame(0x80000081, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // BTR (Bit Test and Reset) Tests - 0x0F 0xB3
    // ========================================

    public function testBtrBitWasSet(): void
    {
        // BTR EAX, ECX - test and reset bit
        // ModRM: r/m=EAX (000), reg=ECX (001) -> 11 001 000 = 0xC8
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);
        $this->setRegister(RegisterType::ECX, 4);

        $this->executeBitOp(0xB3, [0xC8]);

        $this->assertTrue($this->getCarryFlag()); // Was 1
        $this->assertSame(0xFFFFFFEF, $this->getRegister(RegisterType::EAX)); // Bit 4 cleared
    }

    public function testBtrBitWasClear(): void
    {
        // BTR EAX, ECX - test and reset bit that was already clear
        // ModRM: r/m=EAX (000), reg=ECX (001) -> 11 001 000 = 0xC8
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->setRegister(RegisterType::ECX, 4);

        $this->executeBitOp(0xB3, [0xC8]);

        $this->assertFalse($this->getCarryFlag()); // Was 0
        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX)); // Still clear
    }

    // ========================================
    // BTC (Bit Test and Complement) Tests - 0x0F 0xBB
    // ========================================

    public function testBtcBitWasClear(): void
    {
        // BTC EAX, ECX - test and complement bit
        // ModRM: r/m=EAX (000), reg=ECX (001) -> 11 001 000 = 0xC8
        $this->setRegister(RegisterType::EAX, 0x00000000);
        $this->setRegister(RegisterType::ECX, 4);

        $this->executeBitOp(0xBB, [0xC8]);

        $this->assertFalse($this->getCarryFlag()); // Was 0
        $this->assertSame(0x00000010, $this->getRegister(RegisterType::EAX)); // Now 1
    }

    public function testBtcBitWasSet(): void
    {
        // BTC EAX, ECX - test and complement bit that was set
        // ModRM: r/m=EAX (000), reg=ECX (001) -> 11 001 000 = 0xC8
        $this->setRegister(RegisterType::EAX, 0x00000010);
        $this->setRegister(RegisterType::ECX, 4);

        $this->executeBitOp(0xBB, [0xC8]);

        $this->assertTrue($this->getCarryFlag()); // Was 1
        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX)); // Now 0
    }

    public function testBtcToggle(): void
    {
        // BTC twice should restore original value
        // ModRM: r/m=EAX (000), reg=ECX (001) -> 11 001 000 = 0xC8
        $this->setRegister(RegisterType::EAX, 0x12345678);
        $this->setRegister(RegisterType::ECX, 16);

        $this->executeBitOp(0xBB, [0xC8]);
        $this->executeBitOp(0xBB, [0xC8]);

        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EAX));
    }

    // ========================================
    // Memory Operand Tests
    // ========================================

    public function testBtMemory(): void
    {
        // BT [0x1000], EBX
        // ModRM = 0x1D = 00 011 101 (reg=EBX, r/m=disp32)
        $this->writeMemory(0x1000, 0x00000010, 32);
        $this->setRegister(RegisterType::EBX, 4);

        $this->executeBitOp(0xA3, [0x1D, 0x00, 0x10, 0x00, 0x00]);

        $this->assertTrue($this->getCarryFlag());
    }

    public function testBtsMemory(): void
    {
        // BTS [0x1000], EBX
        // ModRM = 0x1D = 00 011 101 (reg=EBX, r/m=disp32)
        $this->writeMemory(0x1000, 0x00000000, 32);
        $this->setRegister(RegisterType::EBX, 8);

        $this->executeBitOp(0xAB, [0x1D, 0x00, 0x10, 0x00, 0x00]);

        $this->assertSame(0x00000100, $this->readMemory(0x1000, 32));
    }

    // ========================================
    // 16-bit Mode Tests
    // ========================================

    public function testBt16Bit(): void
    {
        // ModRM: r/m=AX (000), reg=CX (001) -> 11 001 000 = 0xC8
        $this->setOperandSize(16);
        $this->setRegister(RegisterType::EAX, 0x0080); // Bit 7 set
        $this->setRegister(RegisterType::ECX, 7);

        $this->executeBitOp(0xA3, [0xC8]);

        $this->assertTrue($this->getCarryFlag());
    }

    public function testBts16Bit(): void
    {
        // ModRM: r/m=AX (000), reg=CX (001) -> 11 001 000 = 0xC8
        $this->setOperandSize(16);
        $this->setRegister(RegisterType::EAX, 0x0000);
        $this->setRegister(RegisterType::ECX, 15);

        $this->executeBitOp(0xAB, [0xC8]);

        $this->assertSame(0x8000, $this->getRegister(RegisterType::EAX, 16));
    }

    // ========================================
    // Long mode 64-bit Tests
    // ========================================

    public function testBt64BitHighBit(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x08); // REX.W

        // BT RAX, RCX (ModRM 0xC8)
        $this->setRegister(RegisterType::EAX, PHP_INT_MIN, 64); // bit 63 set
        $this->setRegister(RegisterType::ECX, 63, 64);

        $this->executeBitOp(0xA3, [0xC8]);

        $this->assertTrue($this->getCarryFlag());
    }

    public function testBts64BitSetsBit63(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x08); // REX.W

        // BTS RAX, RCX (ModRM 0xC8)
        $this->setRegister(RegisterType::EAX, 0, 64);
        $this->setRegister(RegisterType::ECX, 63, 64);

        $this->executeBitOp(0xAB, [0xC8]);

        $this->assertSame('0x8000000000000000', UInt64::of($this->getRegister(RegisterType::EAX, 64))->toHex());
        $this->assertFalse($this->getCarryFlag()); // old bit was 0
    }

    public function testBt64BitMemoryUsesBitIndexToSelectQword(): void
    {
        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setRex(0x08); // REX.W

        // Base address in RAX
        $this->setRegister(RegisterType::EAX, 0x2000, 64);

        // Bit 65 => qword index 1, bit 1
        $this->setRegister(RegisterType::ECX, 65, 64);
        $this->writeMemory(0x2000, 0, 64);
        $this->writeMemory(0x2008, 0x2, 64); // bit 1 set

        // BT [RAX], RCX (ModRM: 00 001 000 = 0x08)
        $this->executeBitOp(0xA3, [0x08]);

        $this->assertTrue($this->getCarryFlag());
    }

    // ========================================
    // Helper method
    // ========================================

    private function executeBitOp(int $opcode, array $operandBytes): void
    {
        $this->memoryStream->setOffset(0);
        $code = implode('', array_map('chr', $operandBytes));
        $this->memoryStream->write($code);
        $this->memoryStream->setOffset(0);

        $opcodeKey = (0x0F << 8) | $opcode;
        $this->bitOp->process($this->runtime, [$opcodeKey]);
    }
}
