<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Instruction\Intel\InstructionExecutor;
use PHPMachineEmulator\Instruction\Intel\x86_64;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestArchitectureProvider;
use Tests\Utils\TestRuntime;

final class RedundantPrefixDecodeTest extends TestCase
{
    public function testDecodeExtendsPastMaxOpcodeLengthForRedundantPrefixes(): void
    {
        $instructionList = new x86_64();
        $executor = new InstructionExecutor();

        $arch = new class ($instructionList, $executor) extends TestArchitectureProvider {
            public function __construct(
                private x86_64 $instructionList,
                private InstructionExecutor $executor,
            ) {
            }

            public function instructionList(): x86_64
            {
                return $this->instructionList;
            }

            public function instructionExecutor(): InstructionExecutor
            {
                return $this->executor;
            }
        };

        $runtime = new class ($arch) extends TestRuntime {
            public function __construct(private ArchitectureProviderInterface $arch)
            {
                parent::__construct();
            }

            public function architectureProvider(): ArchitectureProviderInterface
            {
                return $this->arch;
            }
        };

        $instructionList->setRuntime($runtime);
        $runtime->setProtectedMode32();

        // Force single-step execution path (bypass TB/patterns) for a pure decode regression test.
        $runtime->context()->cpu()->iteration()->setIterate(static fn () => null);

        // 6x LOCK prefixes + AND r/m32, r32 (0x21 /r) + ModRM (EAX &= EBX).
        // This exceeds getMaxOpcodeLength()=6, so decoding must peek beyond it.
        $bytes = [
            0xF0, 0xF0, 0xF0, 0xF0, 0xF0, 0xF0,
            0x21,
            0xD8, // 11 011 000 => AND EAX, EBX
        ];

        $runtime->memory()->setOffset(0);
        $runtime->memory()->write(implode('', array_map('chr', $bytes)));
        $runtime->memory()->setOffset(0);

        $runtime->setRegister(RegisterType::EAX, 0xF0F0F0F0, 32);
        $runtime->setRegister(RegisterType::EBX, 0x0F0F0F0F, 32);

        $executor->execute($runtime);

        $this->assertSame(0x00000000, $runtime->getRegister(RegisterType::EAX, 32));
        $this->assertTrue($runtime->getZeroFlag());
        $this->assertSame(8, $runtime->memory()->offset());
    }
}
