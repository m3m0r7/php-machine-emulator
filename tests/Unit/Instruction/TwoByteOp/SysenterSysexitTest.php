<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\TwoByteOp;

use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Sysexit;
use PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp\Sysenter;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\UInt64;

final class SysenterSysexitTest extends TwoByteOpTestCase
{
    protected function createInstruction(): InstructionInterface
    {
        // Not used; individual tests instantiate the target instruction explicitly.
        return new Sysenter($this->instructionList);
    }

    private function writeGdtEntry(int $gdtBase, int $index, array $bytes): void
    {
        $addr = $gdtBase + ($index * 8);
        foreach ($bytes as $i => $b) {
            $this->writeMemory($addr + $i, $b & 0xFF, 8);
        }
    }

    private function initFlat32Gdt(): void
    {
        $this->cpuContext->enableA20(true);

        $gdtBase = 0x2000;
        // 5 descriptors * 8 bytes - 1
        $this->cpuContext->setGdtr($gdtBase, 0x27);

        // 0: null
        $this->writeGdtEntry($gdtBase, 0, [0, 0, 0, 0, 0, 0, 0, 0]);
        // 1: kernel code 0x08 (base=0, limit=4GB, P=1, DPL=0, S=1, exec/read, D=1, G=1)
        $this->writeGdtEntry($gdtBase, 1, [0xFF, 0xFF, 0x00, 0x00, 0x00, 0x9A, 0xCF, 0x00]);
        // 2: kernel data 0x10 (base=0, limit=4GB, P=1, DPL=0, S=1, read/write, D=1, G=1)
        $this->writeGdtEntry($gdtBase, 2, [0xFF, 0xFF, 0x00, 0x00, 0x00, 0x92, 0xCF, 0x00]);
        // 3: user code 0x18/0x1B (base=0, limit=4GB, P=1, DPL=3, S=1, exec/read, D=1, G=1)
        $this->writeGdtEntry($gdtBase, 3, [0xFF, 0xFF, 0x00, 0x00, 0x00, 0xFA, 0xCF, 0x00]);
        // 4: user data 0x20/0x23 (base=0, limit=4GB, P=1, DPL=3, S=1, read/write, D=1, G=1)
        $this->writeGdtEntry($gdtBase, 4, [0xFF, 0xFF, 0x00, 0x00, 0x00, 0xF2, 0xCF, 0x00]);
    }

    public function testSysenterLoadsCsSsEspEipAndCachesDescriptors(): void
    {
        $this->initFlat32Gdt();
        $this->setCpl(0);

        $this->cpuContext->writeMsr(0x174, UInt64::of(0x00000008)); // SYSENTER_CS
        $this->cpuContext->writeMsr(0x175, UInt64::of(0x00002000)); // SYSENTER_ESP
        $this->cpuContext->writeMsr(0x176, UInt64::of(0x00003000)); // SYSENTER_EIP

        (new Sysenter($this->instructionList))->process($this->runtime, [(0x0F << 8) | 0x34]);

        $this->assertSame(0x0008, $this->getRegister(RegisterType::CS, 16));
        $this->assertSame(0x0010, $this->getRegister(RegisterType::SS, 16));
        $this->assertSame(0x00002000, $this->getRegister(RegisterType::ESP, 32));
        $this->assertSame(0x00003000, $this->memoryStream->offset());

        $csCached = $this->cpuContext->getCachedSegmentDescriptor(RegisterType::CS);
        $this->assertIsArray($csCached);
        $this->assertSame(32, $csCached['default']);

        $ssCached = $this->cpuContext->getCachedSegmentDescriptor(RegisterType::SS);
        $this->assertIsArray($ssCached);
        $this->assertSame(32, $ssCached['default']);
    }

    public function testSysexitLoadsUserCsSsEspEipAndCachesDescriptors(): void
    {
        $this->initFlat32Gdt();
        $this->setCpl(0);

        $this->cpuContext->writeMsr(0x174, UInt64::of(0x00000008)); // SYSENTER_CS base

        $this->setRegister(RegisterType::ECX, 0x00112233, 32); // EIP
        $this->setRegister(RegisterType::EDX, 0x00445566, 32); // ESP

        (new Sysexit($this->instructionList))->process($this->runtime, [(0x0F << 8) | 0x35]);

        $this->assertSame(0x001B, $this->getRegister(RegisterType::CS, 16));
        $this->assertSame(0x0023, $this->getRegister(RegisterType::SS, 16));
        $this->assertSame(0x00445566, $this->getRegister(RegisterType::ESP, 32));
        $this->assertSame(0x00112233, $this->memoryStream->offset());
        $this->assertSame(3, $this->cpuContext->cpl());
        $this->assertTrue($this->cpuContext->isUserMode());

        $csCached = $this->cpuContext->getCachedSegmentDescriptor(RegisterType::CS);
        $this->assertIsArray($csCached);
        $this->assertSame(32, $csCached['default']);

        $ssCached = $this->cpuContext->getCachedSegmentDescriptor(RegisterType::SS);
        $this->assertIsArray($ssCached);
        $this->assertSame(32, $ssCached['default']);
    }
}
