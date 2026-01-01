<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\PatternedInstruction;

use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\MemmoveBackwardLoopPattern;
use PHPMachineEmulator\Instruction\RegisterType;
use Tests\Unit\Instruction\InstructionTestCase;

class MemmoveBackwardLoopPatternTest extends InstructionTestCase
{
    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return null;
    }

    public function testCopiesRemainingBytesAndExitsLoopWithCorrectFlags(): void
    {
        $this->cpuContext->enableA20(true);
        $this->cpuContext->setPagingEnabled(false);
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::DS, [
            'base' => 0,
            'limit' => 0xFFFFFFFF,
            'present' => true,
        ]);

        $src = 0x1000;
        $dst = 0x2000;
        $count = 8;

        $this->setRegister(RegisterType::ESI, $src, 32);
        $this->setRegister(RegisterType::EAX, $dst, 32);
        $this->setRegister(RegisterType::ECX, $count, 32);
        $this->setRegister(RegisterType::EDX, 0x12345678, 32);

        for ($i = 0; $i < $count; $i++) {
            $this->writeMemory($src + $i, 0xA0 + $i, 8);
            $this->writeMemory($dst + $i, 0x00, 8);
        }

        $ip = 0xCD75;
        $bytes = [0x83, 0xE9, 0x01, 0x72, 0x08, 0x8A, 0x14, 0x0E, 0x88, 0x14, 0x08, 0xEB, 0xF3];

        $pattern = new MemmoveBackwardLoopPattern();
        $compiled = $pattern->tryCompile($ip, $bytes);
        $this->assertNotNull($compiled);

        $this->memoryStream->setOffset($ip);
        $result = $compiled($this->runtime);
        $this->assertTrue($result->isSuccess());

        $this->assertSame($ip + 13, $this->memoryStream->offset());
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::ECX, 32));

        for ($i = 0; $i < $count; $i++) {
            $this->assertSame(0xA0 + $i, $this->readMemory($dst + $i, 8));
        }

        $edx = $this->getRegister(RegisterType::EDX, 32);
        $this->assertSame(0xA0, $edx & 0xFF);

        $this->assertTrue($this->getCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    public function testZeroCountDoesNotClobberDl(): void
    {
        $this->cpuContext->enableA20(true);
        $this->cpuContext->setPagingEnabled(false);
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::DS, [
            'base' => 0,
            'limit' => 0xFFFFFFFF,
            'present' => true,
        ]);

        $this->setRegister(RegisterType::ESI, 0x1000, 32);
        $this->setRegister(RegisterType::EAX, 0x2000, 32);
        $this->setRegister(RegisterType::ECX, 0, 32);
        $this->setRegister(RegisterType::EDX, 0x12345678, 32);

        $ip = 0xCD75;
        $bytes = [0x83, 0xE9, 0x01, 0x72, 0x08, 0x8A, 0x14, 0x0E, 0x88, 0x14, 0x08, 0xEB, 0xF3];

        $pattern = new MemmoveBackwardLoopPattern();
        $compiled = $pattern->tryCompile($ip, $bytes);
        $this->assertNotNull($compiled);

        $this->memoryStream->setOffset($ip);
        $compiled($this->runtime);

        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::ECX, 32));
        $this->assertSame(0x12345678, $this->getRegister(RegisterType::EDX, 32));
    }

    public function testCopiesRemainingBytesAndExitsLoopWithCorrectFlagsWhenPagingEnabled(): void
    {
        $this->cpuContext->enableA20(true);
        $this->cpuContext->setPagingEnabled(true);
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::DS, [
            'base' => 0,
            'limit' => 0xFFFFFFFF,
            'present' => true,
        ]);

        // Cross a page boundary to exercise chunked translation/copy.
        $src = 0x1FF0;
        $dst = 0x2FF0;
        $count = 0x40;

        $this->setRegister(RegisterType::ESI, $src, 32);
        $this->setRegister(RegisterType::EAX, $dst, 32);
        $this->setRegister(RegisterType::ECX, $count, 32);
        $this->setRegister(RegisterType::EDX, 0x12345678, 32);

        for ($i = 0; $i < $count; $i++) {
            $this->writeMemory($src + $i, 0xC0 + ($i & 0x3F), 8);
            $this->writeMemory($dst + $i, 0x00, 8);
        }

        $ip = 0xCD75;
        $bytes = [0x83, 0xE9, 0x01, 0x72, 0x08, 0x8A, 0x14, 0x0E, 0x88, 0x14, 0x08, 0xEB, 0xF3];

        $pattern = new MemmoveBackwardLoopPattern();
        $compiled = $pattern->tryCompile($ip, $bytes);
        $this->assertNotNull($compiled);

        $this->memoryStream->setOffset($ip);
        $result = $compiled($this->runtime);
        $this->assertTrue($result->isSuccess());

        $this->assertSame($ip + 13, $this->memoryStream->offset());
        $this->assertSame(0xFFFFFFFF, $this->getRegister(RegisterType::ECX, 32));

        for ($i = 0; $i < $count; $i++) {
            $this->assertSame(0xC0 + ($i & 0x3F), $this->readMemory($dst + $i, 8));
        }

        $edx = $this->getRegister(RegisterType::EDX, 32);
        $this->assertSame(0xC0, $edx & 0xFF);

        $this->assertTrue($this->getCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertTrue($this->getSignFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }
}
