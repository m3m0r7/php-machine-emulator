<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\PatternedInstruction;

use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\LzmaRangeDecodeBitPattern;
use PHPMachineEmulator\Instruction\RegisterType;
use Tests\Unit\Instruction\InstructionTestCase;

final class LzmaRangeDecodeBitPatternTest extends InstructionTestCase
{
    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return null;
    }

    public function testBitZeroPathUpdatesProbRangeAndReturns(): void
    {
        $this->cpuContext->enableA20(true);
        $this->cpuContext->setPagingEnabled(false);
        $this->cpuContext->setProtectedMode(true);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(32);
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::CS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true]);
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::DS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true]);
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::SS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true]);

        $ip = 0x89ED;
        $bytes = [
            0x8D, 0x04, 0x83, 0x89, 0xC1, 0x8B, 0x01, 0x8B, 0x55, 0xF4, 0xC1, 0xEA, 0x0B, 0xF7, 0xE2,
            0x3B, 0x45, 0xF0, 0x76, 0x28, 0x89, 0x45, 0xF4, 0xBA, 0x00, 0x08, 0x00, 0x00, 0x2B, 0x11,
            0xC1, 0xEA, 0x05, 0x01, 0x11, 0xF8, 0x9C, 0x81, 0x7D, 0xF4, 0x00, 0x00, 0x00, 0x01, 0x73,
            0x0C, 0xC1, 0x65, 0xF0, 0x08, 0xAC, 0x88, 0x45, 0xF0, 0xC1, 0x65, 0xF4, 0x08, 0x9D, 0xC3,
            0x29, 0x45, 0xF4, 0x29,
        ];

        $pattern = new LzmaRangeDecodeBitPattern();
        $compiled = $pattern->tryCompile($ip, $bytes);
        $this->assertNotNull($compiled);

        // State layout:
        //   [EBP-0x0C] = range
        //   [EBP-0x10] = code
        $ebp = 0x8000;
        $rangeAddr = $ebp - 0x0C;
        $codeAddr = $ebp - 0x10;

        $range = 0x20000000;
        $prob = 0x00000400;
        $code = 0x08000000;
        $rangeShift = ($range >> 11) & 0xFFFFFFFF;
        $bound = ($prob * $rangeShift) & 0xFFFFFFFF;

        // Ensure bit=0 path: code < bound (so bound > code).
        $this->assertTrue(($bound ^ 0x80000000) > ($code ^ 0x80000000));

        $probPtr = 0xB000;
        $this->writeMemory($probPtr, $prob, 32);
        $this->writeMemory($rangeAddr, $range, 32);
        $this->writeMemory($codeAddr, $code, 32);

        $esp = 0x9000;
        $returnIp = 0x2000;
        $this->writeMemory($esp, $returnIp, 32);

        $this->setRegister(RegisterType::EBP, $ebp, 32);
        $this->setRegister(RegisterType::EBX, $probPtr, 32);
        $this->setRegister(RegisterType::EAX, 0, 32); // index
        $this->setRegister(RegisterType::ESP, $esp, 32);
        $this->setRegister(RegisterType::ESI, 0xA000, 32);

        $this->memoryStream->setOffset($ip);
        $result = $compiled($this->runtime);
        $this->assertTrue($result->isSuccess());

        $this->assertSame($returnIp, $this->memoryStream->offset());
        $this->assertSame($esp + 4, $this->getRegister(RegisterType::ESP, 32));

        // bit=0: range=bound, code unchanged, prob updated upward.
        $this->assertSame($bound, $this->readMemory($rangeAddr, 32));
        $this->assertSame($code, $this->readMemory($codeAddr, 32));
        $this->assertSame(0x00000420, $this->readMemory($probPtr, 32));

        $this->assertSame($bound, $this->getRegister(RegisterType::EAX, 32));
        $this->assertSame($probPtr, $this->getRegister(RegisterType::ECX, 32));
        $this->assertSame(0x00000020, $this->getRegister(RegisterType::EDX, 32));

        $this->assertFalse($this->getCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertFalse($this->memoryAccessor->shouldParityFlag());
        $this->assertFalse($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }

    public function testBitOnePathNormalizesAndReturnsWithCarrySet(): void
    {
        $this->cpuContext->enableA20(true);
        $this->cpuContext->setPagingEnabled(false);
        $this->cpuContext->setProtectedMode(true);
        $this->cpuContext->setDefaultOperandSize(32);
        $this->cpuContext->setDefaultAddressSize(32);
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::CS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true]);
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::DS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true]);
        $this->cpuContext->cacheSegmentDescriptor(RegisterType::SS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true]);

        $ip = 0x89ED;
        $bytes = [
            0x8D, 0x04, 0x83, 0x89, 0xC1, 0x8B, 0x01, 0x8B, 0x55, 0xF4, 0xC1, 0xEA, 0x0B, 0xF7, 0xE2,
            0x3B, 0x45, 0xF0, 0x76, 0x28, 0x89, 0x45, 0xF4, 0xBA, 0x00, 0x08, 0x00, 0x00, 0x2B, 0x11,
            0xC1, 0xEA, 0x05, 0x01, 0x11, 0xF8, 0x9C, 0x81, 0x7D, 0xF4, 0x00, 0x00, 0x00, 0x01, 0x73,
            0x0C, 0xC1, 0x65, 0xF0, 0x08, 0xAC, 0x88, 0x45, 0xF0, 0xC1, 0x65, 0xF4, 0x08, 0x9D, 0xC3,
            0x29, 0x45, 0xF4, 0x29,
        ];

        $pattern = new LzmaRangeDecodeBitPattern();
        $compiled = $pattern->tryCompile($ip, $bytes);
        $this->assertNotNull($compiled);

        $ebp = 0x8000;
        $rangeAddr = $ebp - 0x0C;
        $codeAddr = $ebp - 0x10;

        $range = 0x02000000;
        $prob = 0x00000700;
        $rangeShift = ($range >> 11) & 0xFFFFFFFF;
        $bound = ($prob * $rangeShift) & 0xFFFFFFFF;
        $code = $bound; // ensure code >= bound so we take bit=1 branch

        $probPtr = 0xB000;
        $this->writeMemory($probPtr, $prob, 32);
        $this->writeMemory($rangeAddr, $range, 32);
        $this->writeMemory($codeAddr, $code, 32);

        $esi = 0xA000;
        $this->writeMemory($esi, 0xAB, 8);

        $esp = 0x9000;
        $returnIp = 0x2000;
        $this->writeMemory($esp, $returnIp, 32);

        $this->setRegister(RegisterType::EBP, $ebp, 32);
        $this->setRegister(RegisterType::EBX, $probPtr, 32);
        $this->setRegister(RegisterType::EAX, 0, 32); // index
        $this->setRegister(RegisterType::ESP, $esp, 32);
        $this->setRegister(RegisterType::ESI, $esi, 32);

        $this->memoryStream->setOffset($ip);
        $result = $compiled($this->runtime);
        $this->assertTrue($result->isSuccess());

        $this->assertSame($returnIp, $this->memoryStream->offset());
        $this->assertSame($esp + 4, $this->getRegister(RegisterType::ESP, 32));

        // bit=1: range/code reduced by bound, then normalized (range<0x01000000).
        $this->assertSame(0x40000000, $this->readMemory($rangeAddr, 32));
        $this->assertSame(0x000000AB, $this->readMemory($codeAddr, 32));
        $this->assertSame(0x000006C8, $this->readMemory($probPtr, 32));

        $this->assertSame((($bound & 0xFFFFFF00) | 0xAB) & 0xFFFFFFFF, $this->getRegister(RegisterType::EAX, 32));
        $this->assertSame($probPtr, $this->getRegister(RegisterType::ECX, 32));
        $this->assertSame(0x00000038, $this->getRegister(RegisterType::EDX, 32));
        $this->assertSame($esi + 1, $this->getRegister(RegisterType::ESI, 32));

        $this->assertTrue($this->getCarryFlag());
        $this->assertFalse($this->getZeroFlag());
        $this->assertFalse($this->getSignFlag());
        $this->assertFalse($this->getOverflowFlag());
        $this->assertFalse($this->memoryAccessor->shouldParityFlag());
        $this->assertTrue($this->memoryAccessor->shouldAuxiliaryCarryFlag());
    }
}
