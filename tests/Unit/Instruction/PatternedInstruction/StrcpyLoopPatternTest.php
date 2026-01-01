<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\PatternedInstruction;

use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\StrcpyLoopPattern;
use PHPMachineEmulator\Instruction\RegisterType;
use Tests\Unit\Instruction\InstructionTestCase;

class StrcpyLoopPatternTest extends InstructionTestCase
{
    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return null;
    }

    public function testCopiesUntilNulAndUpdatesFlags(): void
    {
        $this->cpuContext->enableA20(true);
        $this->cpuContext->setPagingEnabled(false);
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::DS, [
            'base' => 0,
            'limit' => 0xFFFFFFFF,
            'present' => true,
        ]);

        $src = 0x3000;
        $dst = 0x4000;
        $text = "hello\0";

        for ($i = 0, $len = strlen($text); $i < $len; $i++) {
            $this->writeMemory($src + $i, ord($text[$i]), 8);
            $this->writeMemory($dst + $i, 0x00, 8);
        }

        $this->setRegister(RegisterType::EAX, $dst, 32); // dst base
        $this->setRegister(RegisterType::ECX, $src, 32); // src base
        $this->setRegister(RegisterType::EDX, 0, 32);    // index
        $this->setRegister(RegisterType::EBX, 0x12345678, 32); // ensure BL gets cleared only

        $ip = 0xCD8E;
        $bytes = [0x8A, 0x1C, 0x11, 0x88, 0x1C, 0x10, 0x42, 0x84, 0xDB, 0x75, 0xF5];

        $pattern = new StrcpyLoopPattern();
        $compiled = $pattern->tryCompile($ip, $bytes);
        $this->assertNotNull($compiled);

        $this->memoryStream->setOffset($ip);
        $result = $compiled($this->runtime);
        $this->assertTrue($result->isSuccess());

        $this->assertSame($ip + 11, $this->memoryStream->offset());

        for ($i = 0, $len = strlen($text); $i < $len; $i++) {
            $this->assertSame(ord($text[$i]), $this->readMemory($dst + $i, 8));
        }

        $this->assertSame(strlen($text), $this->getRegister(RegisterType::EDX, 32));

        // BL cleared, upper EBX preserved.
        $this->assertSame(0x12345600, $this->getRegister(RegisterType::EBX, 32));

        // Flags from final TEST BL,BL where BL==0
        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertTrue($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
        $this->assertTrue($this->memoryAccessor->shouldParityFlag());
    }

    public function testSkipsWhenPagingEnabled(): void
    {
        $this->cpuContext->enableA20(true);
        $this->cpuContext->setPagingEnabled(true);
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::DS, [
            'base' => 0,
            'limit' => 0xFFFFFFFF,
            'present' => true,
        ]);

        $this->setRegister(RegisterType::EAX, 0x2000, 32);
        $this->setRegister(RegisterType::ECX, 0x1000, 32);
        $this->setRegister(RegisterType::EDX, 0, 32);

        $ip = 0xCD8E;
        $bytes = [0x8A, 0x1C, 0x11, 0x88, 0x1C, 0x10, 0x42, 0x84, 0xDB, 0x75, 0xF5];

        $pattern = new StrcpyLoopPattern();
        $compiled = $pattern->tryCompile($ip, $bytes);
        $this->assertNotNull($compiled);

        $this->memoryStream->setOffset($ip);
        $result = $compiled($this->runtime);
        $this->assertTrue($result->isSkip());
        $this->assertSame($ip, $this->memoryStream->offset());
    }
}
