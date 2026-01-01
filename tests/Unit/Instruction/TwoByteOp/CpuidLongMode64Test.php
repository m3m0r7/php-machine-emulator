<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Cpuid;
use PHPMachineEmulator\Instruction\RegisterType;

class CpuidLongMode64Test extends TwoByteOpTestCase
{
    private Cpuid $cpuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cpuid = new Cpuid($this->instructionList);
    }

    protected function createInstruction(): InstructionInterface
    {
        return $this->cpuid;
    }

    public function testCpuidLeaf1AdvertisesX8664BaselineBits(): void
    {
        $this->setRegister(RegisterType::EAX, 0x1, 32);
        $this->executeCpuid();

        $edx = $this->getRegister(RegisterType::EDX, 32);
        $this->assertSame(1, ($edx >> 24) & 1, 'FXSR');
        $this->assertSame(1, ($edx >> 25) & 1, 'SSE');
        $this->assertSame(1, ($edx >> 26) & 1, 'SSE2');
        $this->assertSame(1, ($edx >> 15) & 1, 'CMOV');
        $this->assertSame(1, ($edx >> 8) & 1, 'CMPXCHG8B');
    }

    public function testCpuidLeaf80000001AdvertisesLongModeAndSyscall(): void
    {
        $this->setRegister(RegisterType::EAX, 0x80000001, 32);
        $this->executeCpuid();

        $edx = $this->getRegister(RegisterType::EDX, 32);
        $ecx = $this->getRegister(RegisterType::ECX, 32);

        $this->assertSame(1, ($edx >> 29) & 1, 'LM');
        $this->assertSame(1, ($edx >> 11) & 1, 'SYSCALL');
        $this->assertSame(1, ($edx >> 20) & 1, 'NX');
        $this->assertSame(1, ($ecx >> 0) & 1, 'LAHF/SAHF in long mode');
    }

    public function testCpuidLeaf80000008ReportsAddressWidths(): void
    {
        $this->setRegister(RegisterType::EAX, 0x80000008, 32);
        $this->executeCpuid();

        $eax = $this->getRegister(RegisterType::EAX, 32);
        $physical = $eax & 0xFF;
        $linear = ($eax >> 8) & 0xFF;

        $this->assertSame(36, $physical);
        $this->assertSame(48, $linear);
    }

    private function executeCpuid(): void
    {
        $opcodeKey = (0x0F << 8) | 0xA2;
        $this->cpuid->process($this->runtime, [$opcodeKey]);
    }
}
