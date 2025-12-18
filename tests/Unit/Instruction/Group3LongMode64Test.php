<?php
declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Group3;
use PHPMachineEmulator\Instruction\RegisterType;

final class Group3LongMode64Test extends InstructionTestCase
{
    private Group3 $group3;

    protected function setUp(): void
    {
        parent::setUp();

        $instructionList = $this->createMock(InstructionListInterface::class);
        $this->group3 = new Group3($instructionList);

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);
        $this->cpuContext->clearRex();
    }

    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return null;
    }

    private function setRexW(bool $enabled): void
    {
        if (!$enabled) {
            $this->cpuContext->clearRex();
            return;
        }
        $this->cpuContext->setRex(0x8);
    }

    /**
     * @param int[] $bytes ModR/M (+ immediate) bytes (opcode byte is not included)
     */
    private function executeGroup3(int $opcode, array $bytes): void
    {
        $this->memoryStream->setOffset(0);
        $this->memoryStream->write(implode('', array_map('chr', $bytes)));
        $this->memoryStream->setOffset(0);
        $this->group3->process($this->runtime, [$opcode]);
    }

    public function testTestRaxImm32IsSignExtendedTo64(): void
    {
        $this->setRexW(true);

        // RAX has a bit set above 32-bit range.
        $this->setRegister(RegisterType::EAX, 1 << 32, 64);

        // TEST r/m64, imm32 (F7 /0 id)
        // ModR/M: 11 000 000 => TEST RAX, imm32
        // imm32=0xFFFFFFFF should be sign-extended to 64 (i.e., -1), so result mirrors RAX and ZF must be 0.
        $this->executeGroup3(0xF7, [0xC0, 0xFF, 0xFF, 0xFF, 0xFF]);

        $this->assertSame(1 << 32, $this->getRegister(RegisterType::EAX, 64));
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
    }

    public function testNotRaxUses64BitOperandWidth(): void
    {
        $this->setRexW(true);

        $original = 0x0F0F0F0F0F0F0F0F;
        $this->setRegister(RegisterType::EAX, $original, 64);

        // NOT r/m64 (F7 /2)
        // ModR/M: 11 010 000 => NOT RAX
        $this->executeGroup3(0xF7, [0xD0]);

        $this->assertSame(~$original, $this->getRegister(RegisterType::EAX, 64));
    }

    public function testNegRaxMostNegativeSetsOverflow(): void
    {
        $this->setRexW(true);

        $this->setRegister(RegisterType::EAX, PHP_INT_MIN, 64);

        // NEG r/m64 (F7 /3)
        // ModR/M: 11 011 000 => NEG RAX
        $this->executeGroup3(0xF7, [0xD8]);

        $this->assertSame(PHP_INT_MIN, $this->getRegister(RegisterType::EAX, 64));
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
    }

    public function testMulRcxUnsigned64WritesRdxRax(): void
    {
        $this->setRexW(true);

        // RAX = 0xFFFFFFFFFFFFFFFF (unsigned 2^64-1)
        $this->setRegister(RegisterType::EAX, -1, 64);
        $this->setRegister(RegisterType::ECX, 2, 64);
        $this->setRegister(RegisterType::EDX, 0, 64);

        // MUL r/m64 (F7 /4)
        // ModR/M: 11 100 001 => MUL RCX
        $this->executeGroup3(0xF7, [0xE1]);

        $this->assertSame(-2, $this->getRegister(RegisterType::EAX, 64)); // 0xFFFFFFFFFFFFFFFE
        $this->assertSame(1, $this->getRegister(RegisterType::EDX, 64));  // high 64 bits
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag());
    }

    public function testImulRcxSigned64OverflowSetsFlags(): void
    {
        $this->setRexW(true);

        $this->setRegister(RegisterType::EAX, 1 << 62, 64);
        $this->setRegister(RegisterType::ECX, 4, 64);
        $this->setRegister(RegisterType::EDX, 0, 64);

        // IMUL r/m64 (F7 /5)
        // ModR/M: 11 101 001 => IMUL RCX
        $this->executeGroup3(0xF7, [0xE9]);

        $this->assertSame(0, $this->getRegister(RegisterType::EAX, 64));
        $this->assertSame(1, $this->getRegister(RegisterType::EDX, 64));
        $this->assertTrue($this->getCarryFlag());
        $this->assertTrue($this->getOverflowFlag());
    }

    public function testImulRcxSigned64NoOverflowSignExtendsHigh(): void
    {
        $this->setRexW(true);

        $this->setRegister(RegisterType::EAX, -10, 64);
        $this->setRegister(RegisterType::ECX, 5, 64);
        $this->setRegister(RegisterType::EDX, 0, 64);

        // IMUL r/m64 (F7 /5)
        // ModR/M: 11 101 001 => IMUL RCX
        $this->executeGroup3(0xF7, [0xE9]);

        $this->assertSame(-50, $this->getRegister(RegisterType::EAX, 64));
        $this->assertSame(-1, $this->getRegister(RegisterType::EDX, 64));
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
    }

    public function testDivRcxUnsigned64WritesRdxRax(): void
    {
        $this->setRexW(true);

        $this->setRegister(RegisterType::EAX, 100, 64);
        $this->setRegister(RegisterType::EDX, 0, 64);
        $this->setRegister(RegisterType::ECX, 7, 64);

        // DIV r/m64 (F7 /6)
        // ModR/M: 11 110 001 => DIV RCX
        $this->executeGroup3(0xF7, [0xF1]);

        $this->assertSame(14, $this->getRegister(RegisterType::EAX, 64));
        $this->assertSame(2, $this->getRegister(RegisterType::EDX, 64));
    }

    public function testDivRcxUnsigned64OverflowThrowsDe(): void
    {
        $this->setRexW(true);

        $this->setRegister(RegisterType::EAX, 0, 64);
        $this->setRegister(RegisterType::EDX, 1, 64); // dividend = 2^64
        $this->setRegister(RegisterType::ECX, 1, 64); // divisor = 1 => quotient = 2^64 (overflow)

        $this->expectException(FaultException::class);
        $this->executeGroup3(0xF7, [0xF1]);
    }

    public function testIdivRcxSigned64WritesRdxRax(): void
    {
        $this->setRexW(true);

        $this->setRegister(RegisterType::EAX, -100, 64);
        $this->setRegister(RegisterType::EDX, -1, 64);
        $this->setRegister(RegisterType::ECX, 7, 64);

        // IDIV r/m64 (F7 /7)
        // ModR/M: 11 111 001 => IDIV RCX
        $this->executeGroup3(0xF7, [0xF9]);

        $this->assertSame(-14, $this->getRegister(RegisterType::EAX, 64));
        $this->assertSame(-2, $this->getRegister(RegisterType::EDX, 64));
    }

    public function testIdivRcxSigned64OverflowThrowsDe(): void
    {
        $this->setRexW(true);

        $this->setRegister(RegisterType::EAX, PHP_INT_MIN, 64);
        $this->setRegister(RegisterType::EDX, -1, 64);
        $this->setRegister(RegisterType::ECX, -1, 64);

        $this->expectException(FaultException::class);
        $this->executeGroup3(0xF7, [0xF9]);
    }
}

