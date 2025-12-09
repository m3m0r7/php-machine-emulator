<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Bswap;
use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for BSWAP instruction.
 *
 * BSWAP r32: 0x0F 0xC8+rd
 * Reverses the byte order of a 32-bit register.
 */
class BswapTest extends TwoByteOpTestCase
{
    private Bswap $bswap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bswap = new Bswap($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->bswap;
    }

    public function testBswapEax(): void
    {
        // BSWAP EAX (0x0F 0xC8)
        $this->setRegister(RegisterType::EAX, 0x12345678);

        $this->executeBswap(0xC8);

        $this->assertSame(0x78563412, $this->getRegister(RegisterType::EAX));
    }

    public function testBswapEcx(): void
    {
        // BSWAP ECX (0x0F 0xC9)
        $this->setRegister(RegisterType::ECX, 0xAABBCCDD);

        $this->executeBswap(0xC9);

        $this->assertSame(0xDDCCBBAA, $this->getRegister(RegisterType::ECX));
    }

    public function testBswapEdx(): void
    {
        // BSWAP EDX (0x0F 0xCA)
        $this->setRegister(RegisterType::EDX, 0xDEADBEEF);

        $this->executeBswap(0xCA);

        $this->assertSame(0xEFBEADDE, $this->getRegister(RegisterType::EDX));
    }

    public function testBswapEbx(): void
    {
        // BSWAP EBX (0x0F 0xCB)
        $this->setRegister(RegisterType::EBX, 0xCAFEBABE);

        $this->executeBswap(0xCB);

        $this->assertSame(0xBEBAFECA, $this->getRegister(RegisterType::EBX));
    }

    public function testBswapEsp(): void
    {
        // BSWAP ESP (0x0F 0xCC)
        $this->setRegister(RegisterType::ESP, 0x11223344);

        $this->executeBswap(0xCC);

        $this->assertSame(0x44332211, $this->getRegister(RegisterType::ESP));
    }

    public function testBswapEbp(): void
    {
        // BSWAP EBP (0x0F 0xCD)
        $this->setRegister(RegisterType::EBP, 0x55667788);

        $this->executeBswap(0xCD);

        $this->assertSame(0x88776655, $this->getRegister(RegisterType::EBP));
    }

    public function testBswapEsi(): void
    {
        // BSWAP ESI (0x0F 0xCE)
        $this->setRegister(RegisterType::ESI, 0x99AABBCC);

        $this->executeBswap(0xCE);

        $this->assertSame(0xCCBBAA99, $this->getRegister(RegisterType::ESI));
    }

    public function testBswapEdi(): void
    {
        // BSWAP EDI (0x0F 0xCF)
        $this->setRegister(RegisterType::EDI, 0xDDEEFF00);

        $this->executeBswap(0xCF);

        $this->assertSame(0x00FFEEDD, $this->getRegister(RegisterType::EDI));
    }

    public function testBswapZero(): void
    {
        // BSWAP EAX with zero
        $this->setRegister(RegisterType::EAX, 0x00000000);

        $this->executeBswap(0xC8);

        $this->assertSame(0x00000000, $this->getRegister(RegisterType::EAX));
    }

    public function testBswapPalindrome(): void
    {
        // BSWAP EAX with palindromic value
        $this->setRegister(RegisterType::EAX, 0xABCDDCBA);

        $this->executeBswap(0xC8);

        $this->assertSame(0xBADCCDAB, $this->getRegister(RegisterType::EAX));
    }

    public function testBswapTwiceRestoresOriginal(): void
    {
        // BSWAP twice should restore original value
        $original = 0x12345678;
        $this->setRegister(RegisterType::EAX, $original);

        $this->executeBswap(0xC8);
        $this->executeBswap(0xC8);

        $this->assertSame($original, $this->getRegister(RegisterType::EAX));
    }

    public function testBswapMaxValue(): void
    {
        $this->setRegister(RegisterType::EAX, 0xFFFFFFFF);

        $this->executeBswap(0xC8);

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::EAX));
    }

    private function executeBswap(int $opcode): void
    {
        $opcodeKey = (0x0F << 8) | $opcode;
        $this->bswap->process($this->runtime, [$opcodeKey]);
    }
}
