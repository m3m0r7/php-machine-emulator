<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\CmpJccPattern;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestRuntime;

class CmpJccPatternTest extends TestCase
{
    public function testLongJccUsesRel16WhenOperandSizeIs16(): void
    {
        $runtime = new TestRuntime();
        $runtime->setRealMode16();
        $runtime->setRegister(RegisterType::EAX, 0x1234, 32);

        $ip = 0x1000;

        // CMP AX, AX; JZ near +0x00CD; next bytes are a JMP (must NOT be consumed as rel32)
        $bytes = [
            0x39, 0xC0,
            0x0F, 0x84,
            0xCD, 0x00,
            0xE9, 0xF3,
        ];

        $compiled = (new CmpJccPattern())->tryCompile($ip, $bytes);
        $this->assertIsCallable($compiled);

        $runtime->memory()->setOffset($ip);
        $compiled($runtime);

        $this->assertSame(0x10D3, $runtime->memory()->offset());
    }

    public function testLongJccUsesRel32WhenOperandSizeIs32(): void
    {
        $runtime = new TestRuntime();
        $runtime->setProtectedMode32();
        $runtime->setRegister(RegisterType::EAX, 0x12345678, 32);

        $ip = 0x0100;

        // CMP EAX, EAX; JZ near rel32=0x00010005
        $bytes = [
            0x39, 0xC0,
            0x0F, 0x84,
            0x05, 0x00, 0x01, 0x00,
        ];

        $compiled = (new CmpJccPattern())->tryCompile($ip, $bytes);
        $this->assertIsCallable($compiled);

        $runtime->memory()->setOffset($ip);
        $compiled($runtime);

        $this->assertSame(0x1010D, $runtime->memory()->offset());
    }
}

