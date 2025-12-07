<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPMachineEmulator\Collection\MemoryAccessorObserverCollection;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\MemoryAccessor;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Runtime\RuntimeOption;
use PHPMachineEmulator\Runtime\RuntimeContext;
use PHPMachineEmulator\Runtime\RuntimeContextInterface;
use PHPMachineEmulator\Runtime\RuntimeCPUContext;
use PHPMachineEmulator\Runtime\RuntimeScreenContextInterface;
use PHPMachineEmulator\Runtime\Device\DeviceManager;
use PHPUnit\Framework\TestCase;

class MemoryAccessorGprTest extends TestCase
{
    private MemoryAccessor $memoryAccessor;

    protected function setUp(): void
    {
        $cpuContext = new RuntimeCPUContext();
        $screenContext = $this->createMock(RuntimeScreenContextInterface::class);
        $deviceManager = new DeviceManager();

        $runtimeContext = new RuntimeContext($cpuContext, $screenContext, $deviceManager);

        $register = new Register();

        $runtime = $this->createMock(RuntimeInterface::class);
        $runtime->method('context')->willReturn($runtimeContext);
        $runtime->method('register')->willReturn($register);

        $observers = new MemoryAccessorObserverCollection();

        $this->memoryAccessor = new MemoryAccessor($runtime, $observers);

        // Allocate GPRs (addresses 0-7)
        for ($i = 0; $i <= 7; $i++) {
            $this->memoryAccessor->allocate($i);
        }
    }

    public function testWrite16BitAndRead16Bit(): void
    {
        $this->memoryAccessor->write16Bit(RegisterType::EAX, 0x1234);

        $result = $this->memoryAccessor->fetch(RegisterType::EAX)->asByte();

        $this->assertSame(0x1234, $result);
    }

    public function testWrite16BitPreservesUpper16Bits(): void
    {
        // First write a 32-bit value
        $this->memoryAccessor->writeBySize(RegisterType::EAX, 0xABCD1234, 32);

        // Now write a 16-bit value - should preserve upper 16 bits
        $this->memoryAccessor->write16Bit(RegisterType::EAX, 0x5678);

        // Read back as 32-bit - upper bits should be preserved
        $result = $this->memoryAccessor->fetch(RegisterType::EAX)->asBytesBySize(32);

        $this->assertSame(0xABCD5678, $result);
    }

    public function testWrite32BitAndRead32Bit(): void
    {
        $this->memoryAccessor->writeBySize(RegisterType::EBX, 0xDEADBEEF, 32);

        $result = $this->memoryAccessor->fetch(RegisterType::EBX)->asBytesBySize(32);

        $this->assertSame(0xDEADBEEF, $result);
    }

    public function testWrite32BitAndRead16Bit(): void
    {
        $this->memoryAccessor->writeBySize(RegisterType::ECX, 0x12345678, 32);

        // Reading 16-bit should return lower 16 bits
        $result = $this->memoryAccessor->fetch(RegisterType::ECX)->asByte();

        $this->assertSame(0x5678, $result);
    }

    public function testWriteLowBitPreservesOtherBits(): void
    {
        // Set up initial value
        $this->memoryAccessor->writeBySize(RegisterType::EDX, 0xAABBCCDD, 32);

        // Write to low bit (AL equivalent)
        $this->memoryAccessor->writeToLowBit(RegisterType::EDX, 0x11);

        // Check that only low byte changed
        $result = $this->memoryAccessor->fetch(RegisterType::EDX)->asBytesBySize(32);

        $this->assertSame(0xAABBCC11, $result);
    }

    public function testWriteHighBitPreservesOtherBits(): void
    {
        // Set up initial value
        $this->memoryAccessor->writeBySize(RegisterType::EDX, 0xAABBCCDD, 32);

        // Write to high bit (AH equivalent)
        $this->memoryAccessor->writeToHighBit(RegisterType::EDX, 0x22);

        // Check that only high byte (bits 8-15) changed
        $result = $this->memoryAccessor->fetch(RegisterType::EDX)->asBytesBySize(32);

        $this->assertSame(0xAABB22DD, $result);
    }

    public function testReadLowBitFrom32BitValue(): void
    {
        $this->memoryAccessor->writeBySize(RegisterType::ESI, 0x12345678, 32);

        $result = $this->memoryAccessor->fetch(RegisterType::ESI)->asLowBit();

        $this->assertSame(0x78, $result);
    }

    public function testReadHighBitFrom32BitValue(): void
    {
        $this->memoryAccessor->writeBySize(RegisterType::EDI, 0x12345678, 32);

        $result = $this->memoryAccessor->fetch(RegisterType::EDI)->asHighBit();

        $this->assertSame(0x56, $result);
    }

    public function testMultipleWritesWithDifferentSizes(): void
    {
        // Write 32-bit
        $this->memoryAccessor->writeBySize(RegisterType::EAX, 0xFFFFFFFF, 32);
        $this->assertSame(0xFFFFFFFF, $this->memoryAccessor->fetch(RegisterType::EAX)->asBytesBySize(32));

        // Write 16-bit - should preserve upper bits
        $this->memoryAccessor->write16Bit(RegisterType::EAX, 0x0000);
        $this->assertSame(0xFFFF0000, $this->memoryAccessor->fetch(RegisterType::EAX)->asBytesBySize(32));

        // Write low byte
        $this->memoryAccessor->writeToLowBit(RegisterType::EAX, 0xAA);
        $this->assertSame(0xFFFF00AA, $this->memoryAccessor->fetch(RegisterType::EAX)->asBytesBySize(32));

        // Write high byte
        $this->memoryAccessor->writeToHighBit(RegisterType::EAX, 0xBB);
        $this->assertSame(0xFFFFBBAA, $this->memoryAccessor->fetch(RegisterType::EAX)->asBytesBySize(32));
    }

    public function testFetchByRawAddressWorksCorrectly(): void
    {
        // Write using RegisterType
        $this->memoryAccessor->writeBySize(RegisterType::EBX, 0x12345678, 32);

        // Read using raw address (EBX = address 3)
        $result = $this->memoryAccessor->fetch(3)->asBytesBySize(32);

        $this->assertSame(0x12345678, $result);
    }
}
