<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Group6;
use PHPMachineEmulator\Instruction\RegisterType;

final class Group6LongMode64Test extends TwoByteOpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->cpuContext->setLongMode(true);
        $this->cpuContext->setCompatibilityMode(false);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(64);
    }

    protected function createInstruction(): InstructionInterface
    {
        return new Group6($this->instructionList);
    }

    private function writePseudoDescriptor10(int $address, int $base, int $limit): void
    {
        $this->writeMemory($address, $limit & 0xFFFF, 16);
        $this->writeMemory($address + 2, $base & 0xFFFFFFFF, 32);
        $this->writeMemory($address + 6, ($base >> 32) & 0xFFFFFFFF, 32);
    }

    private function readPseudoDescriptorBase10(int $address): int
    {
        $low = $this->readMemory($address + 2, 32);
        $high = $this->readMemory($address + 6, 32);
        return $low | ($high << 32);
    }

    public function testLgdtLoads10ByteDescriptorInLongMode(): void
    {
        $gdtrAddr = 0x2000;
        $this->setRegister(RegisterType::EAX, $gdtrAddr, 64); // RAX points to pseudo-descriptor

        $base = 0x0000123456789ABC;
        $limit = 0x00FF;
        $this->writePseudoDescriptor10($gdtrAddr, $base, $limit);

        // ModR/M: 00 010 000 => LGDT [RAX]
        $this->executeTwoByteOp(0x01, [0x10]);

        $gdtr = $this->cpuContext->gdtr();
        $this->assertSame($limit, $gdtr['limit']);
        $this->assertSame($base, $gdtr['base']);
    }

    public function testLidtLoads10ByteDescriptorInLongMode(): void
    {
        $idtrAddr = 0x2100;
        $this->setRegister(RegisterType::EAX, $idtrAddr, 64); // RAX points to pseudo-descriptor

        $base = 0x0000FEDCBA987654;
        $limit = 0x0FFF;
        $this->writePseudoDescriptor10($idtrAddr, $base, $limit);

        // ModR/M: 00 011 000 => LIDT [RAX]
        $this->executeTwoByteOp(0x01, [0x18]);

        $idtr = $this->cpuContext->idtr();
        $this->assertSame($limit, $idtr['limit']);
        $this->assertSame($base, $idtr['base']);
    }

    public function testSgdtStores10ByteDescriptorInLongMode(): void
    {
        $storeAddr = 0x2200;
        $this->setRegister(RegisterType::EAX, $storeAddr, 64); // RAX points to destination

        $base = 0x0000111122223333;
        // Keep the limit below 8 so segment lookups won't try to read the (out-of-range) GDT base.
        $limit = 0x0006;
        $this->cpuContext->setGdtr($base, $limit);

        // ModR/M: 00 000 000 => SGDT [RAX]
        $this->executeTwoByteOp(0x01, [0x00]);

        $this->assertSame($limit, $this->readMemory($storeAddr, 16));
        $this->assertSame($base, $this->readPseudoDescriptorBase10($storeAddr));
    }

    public function testSidtStores10ByteDescriptorInLongMode(): void
    {
        $storeAddr = 0x2300;
        $this->setRegister(RegisterType::EAX, $storeAddr, 64); // RAX points to destination

        $base = 0x0000444455556666;
        $limit = 0x0BBB;
        $this->cpuContext->setIdtr($base, $limit);

        // ModR/M: 00 001 000 => SIDT [RAX]
        $this->executeTwoByteOp(0x01, [0x08]);

        $this->assertSame($limit, $this->readMemory($storeAddr, 16));
        $this->assertSame($base, $this->readPseudoDescriptorBase10($storeAddr));
    }
}
