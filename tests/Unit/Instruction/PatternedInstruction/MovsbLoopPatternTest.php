<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\PatternedInstruction;

use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\MovsbLoopPattern;
use PHPMachineEmulator\Instruction\RegisterType;
use Tests\Unit\Instruction\InstructionTestCase;

class MovsbLoopPatternTest extends InstructionTestCase
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
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::ES, [
            'base' => 0,
            'limit' => 0xFFFFFFFF,
            'present' => true,
        ]);

        $src = 0x1000;
        $dst = 0x2000;
        $count = 8;

        $this->setRegister(RegisterType::ESI, $src, 32);
        $this->setRegister(RegisterType::EDI, $dst, 32);
        $this->setRegister(RegisterType::ECX, $dst + $count, 32);

        for ($i = 0; $i < $count; $i++) {
            $this->writeMemory($src + $i, 0xB0 + $i, 8);
            $this->writeMemory($dst + $i, 0x00, 8);
        }

        $ip = 0xCD6E;
        $bytes = [0x39, 0xF9, 0x74, 0x10, 0xA4, 0xEB, 0xF9];

        $pattern = new MovsbLoopPattern();
        $compiled = $pattern->tryCompile($ip, $bytes);
        $this->assertNotNull($compiled);

        $this->memoryStream->setOffset($ip);
        $result = $compiled($this->runtime);
        $this->assertTrue($result->isSuccess());

        $this->assertSame($ip + 0x14, $this->memoryStream->offset());
        $this->assertSame($dst + $count, $this->getRegister(RegisterType::EDI, 32));
        $this->assertSame($src + $count, $this->getRegister(RegisterType::ESI, 32));

        for ($i = 0; $i < $count; $i++) {
            $this->assertSame(0xB0 + $i, $this->readMemory($dst + $i, 8));
        }

        $this->assertFalse($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag());
        $this->assertFalse($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    public function testOverlapCaseSkipsPattern(): void
    {
        $this->cpuContext->enableA20(true);
        $this->cpuContext->setPagingEnabled(false);
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::DS, [
            'base' => 0,
            'limit' => 0xFFFFFFFF,
            'present' => true,
        ]);
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::ES, [
            'base' => 0,
            'limit' => 0xFFFFFFFF,
            'present' => true,
        ]);

        $src = 0x1000;
        $dst = 0x1004; // overlaps when copying forward
        $count = 8;

        $this->setRegister(RegisterType::ESI, $src, 32);
        $this->setRegister(RegisterType::EDI, $dst, 32);
        $this->setRegister(RegisterType::ECX, $dst + $count, 32);

        $ip = 0xCD6E;
        $bytes = [0x39, 0xF9, 0x74, 0x10, 0xA4, 0xEB, 0xF9];

        $pattern = new MovsbLoopPattern();
        $compiled = $pattern->tryCompile($ip, $bytes);
        $this->assertNotNull($compiled);

        $this->memoryStream->setOffset($ip);
        $result = $compiled($this->runtime);
        $this->assertTrue($result->isSkip());
        $this->assertSame($ip, $this->memoryStream->offset());
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
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::ES, [
            'base' => 0,
            'limit' => 0xFFFFFFFF,
            'present' => true,
        ]);

        // Cross a page boundary to exercise chunked translation/copy.
        $src = 0x1FF0;
        $dst = 0x2FF0;
        $count = 0x40;

        $this->setRegister(RegisterType::ESI, $src, 32);
        $this->setRegister(RegisterType::EDI, $dst, 32);
        $this->setRegister(RegisterType::ECX, $dst + $count, 32);

        for ($i = 0; $i < $count; $i++) {
            $this->writeMemory($src + $i, 0x80 + ($i & 0x3F), 8);
            $this->writeMemory($dst + $i, 0x00, 8);
        }

        $ip = 0xCD6E;
        $bytes = [0x39, 0xF9, 0x74, 0x10, 0xA4, 0xEB, 0xF9];

        $pattern = new MovsbLoopPattern();
        $compiled = $pattern->tryCompile($ip, $bytes);
        $this->assertNotNull($compiled);

        $this->memoryStream->setOffset($ip);
        $result = $compiled($this->runtime);
        $this->assertTrue($result->isSuccess());

        $this->assertSame($ip + 0x14, $this->memoryStream->offset());
        $this->assertSame($dst + $count, $this->getRegister(RegisterType::EDI, 32));
        $this->assertSame($src + $count, $this->getRegister(RegisterType::ESI, 32));

        for ($i = 0; $i < $count; $i++) {
            $this->assertSame(0x80 + ($i & 0x3F), $this->readMemory($dst + $i, 8));
        }

        $this->assertFalse($this->getCarryFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag());
        $this->assertFalse($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }
}
