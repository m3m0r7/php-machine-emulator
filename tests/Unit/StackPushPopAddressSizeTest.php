<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPMachineEmulator\Collection\MemoryAccessorObserverCollection;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\MemoryAccessor;
use PHPMachineEmulator\Runtime\RuntimeContext;
use PHPMachineEmulator\Runtime\RuntimeCPUContext;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Runtime\RuntimeScreenContextInterface;
use PHPMachineEmulator\Runtime\Device\DeviceManager;
use PHPMachineEmulator\Stream\MemoryStream;
use PHPUnit\Framework\TestCase;

final class StackPushPopAddressSizeTest extends TestCase
{
    public function testPushPop16UsesFullEspWhenStackIs32Bit(): void
    {
        $cpuContext = new RuntimeCPUContext();
        $cpuContext->setProtectedMode(true);

        // Simulate a 32-bit stack segment (SS descriptor B=1) as cached by MOV SS, r/m16.
        $cpuContext->cacheSegmentDescriptor(RegisterType::SS, [
            'base' => 0,
            'limit' => 0xFFFFFFFF,
            'present' => true,
            'type' => 0x2,
            'system' => false,
            'executable' => false,
            'dpl' => 0,
            'default' => 32,
        ]);

        $screenContext = $this->createMock(RuntimeScreenContextInterface::class);
        $deviceManager = new DeviceManager();
        $runtimeContext = new RuntimeContext($cpuContext, $screenContext, $deviceManager);
        $register = new Register();
        $memory = new MemoryStream(0x100000, 0x100000, 0);

        $runtime = $this->createMock(RuntimeInterface::class);
        $runtime->method('context')->willReturn($runtimeContext);
        $runtime->method('register')->willReturn($register);
        $runtime->method('memory')->willReturn($memory);

        $memoryAccessor = new MemoryAccessor($runtime, new MemoryAccessorObserverCollection());

        // 32-bit stack pointer above 64KB (matches GRUB stage behavior: ESP=0x0007FFF0).
        $memoryAccessor->write16Bit(RegisterType::SS, 0x0010);
        $memoryAccessor->writeBySize(RegisterType::ESP, 0x0007FFF0, 32);

        $memoryAccessor->push(RegisterType::ESP, 0x1234, 16);
        $this->assertSame(0x0007FFEE, $memoryAccessor->fetch(RegisterType::ESP)->asBytesBySize(32));

        // Value must land at 0x0007FFEE (not 0x0000FFEE).
        $this->assertSame(0x1234, $memoryAccessor->readPhysical16(0x0007FFEE));

        $popped = $memoryAccessor->pop(RegisterType::ESP, 16)->asBytesBySize(16);
        $this->assertSame(0x1234, $popped);
        $this->assertSame(0x0007FFF0, $memoryAccessor->fetch(RegisterType::ESP)->asBytesBySize(32));
    }
}

