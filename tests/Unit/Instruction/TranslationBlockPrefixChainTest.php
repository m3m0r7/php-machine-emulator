<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\Intel\TranslationBlock;
use PHPMachineEmulator\Instruction\Intel\x86_64 as X86_64InstructionList;
use PHPMachineEmulator\Instruction\Intel\x86_64\RexPrefix;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestRuntime;

final class TranslationBlockPrefixChainTest extends TestCase
{
    public function testTranslationBlockDoesNotClearRexOnContinue(): void
    {
        $instructionList = new X86_64InstructionList();
        $rexPrefix = new RexPrefix($instructionList);

        $runtime = new TestRuntime();
        $runtime->cpuContext()->setLongMode(true);
        $runtime->cpuContext()->setCompatibilityMode(false);

        $tb = new TranslationBlock(
            startIp: 0,
            instructions: [
                // One-byte REX.W prefix at IP=0
                [$rexPrefix, [0x48], 1],
            ],
            totalLength: 1,
        );

        [$status, $exitIp] = $tb->execute($runtime);

        $this->assertSame(ExecutionStatus::CONTINUE, $status);
        $this->assertSame(1, $exitIp);
        $this->assertTrue($runtime->cpuContext()->rexW(), 'REX.W must remain active after CONTINUE');
    }
}

