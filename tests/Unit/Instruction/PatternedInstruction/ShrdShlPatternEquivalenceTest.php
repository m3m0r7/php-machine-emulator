<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\PatternedInstruction;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Instruction\Intel\InstructionExecutor;
use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\ShrdShlPattern;
use PHPMachineEmulator\Instruction\Intel\x86_64;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestArchitectureProvider;
use Tests\Utils\TestRuntime;

final class ShrdShlPatternEquivalenceTest extends TestCase
{
    /**
     * @return array{runtime: TestRuntime, list: x86_64, executor: InstructionExecutor}
     */
    private function newRuntimeWithRealExecutor(): array
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

        return ['runtime' => $runtime, 'list' => $instructionList, 'executor' => $executor];
    }

    private function initFlatProtected32(TestRuntime $runtime): void
    {
        $runtime->cpuContext()->enableA20(true);
        $runtime->cpuContext()->setPagingEnabled(false);
        $runtime->cpuContext()->setProtectedMode(true);
        $runtime->cpuContext()->setDefaultOperandSize(32);
        $runtime->cpuContext()->setDefaultAddressSize(32);

        $runtime->cpuContext()->cacheSegmentDescriptor(RegisterType::CS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true, 'default' => 32]);
        $runtime->cpuContext()->cacheSegmentDescriptor(RegisterType::DS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true, 'default' => 32]);
        $runtime->cpuContext()->cacheSegmentDescriptor(RegisterType::SS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true, 'default' => 32]);

        $runtime->cpuContext()->setGdtr(0x5000, 0x17);

        $gdt = str_repeat("\x00", 8)
            . "\xFF\xFF\x00\x00\x00\x9A\xCF\x00"
            . "\xFF\xFF\x00\x00\x00\x92\xCF\x00";
        $runtime->memory()->setOffset(0x5000);
        $runtime->memory()->write($gdt);

        $runtime->setRegister(RegisterType::CS, 0x0008, 16);
        $runtime->setRegister(RegisterType::DS, 0x0010, 16);
        $runtime->setRegister(RegisterType::SS, 0x0010, 16);
    }

    /**
     * @return array<string,int|bool>
     */
    private function snapshotState(TestRuntime $runtime): array
    {
        $ma = $runtime->memoryAccessor();
        return [
            'ip' => $runtime->memory()->offset() & 0xFFFFFFFF,
            'eax' => $runtime->getRegister(RegisterType::EAX, 32) & 0xFFFFFFFF,
            'ebx' => $runtime->getRegister(RegisterType::EBX, 32) & 0xFFFFFFFF,
            'cf' => $ma->shouldCarryFlag(),
            'zf' => $ma->shouldZeroFlag(),
            'sf' => $ma->shouldSignFlag(),
            'of' => $ma->shouldOverflowFlag(),
            'pf' => $ma->shouldParityFlag(),
            'af' => $ma->shouldAuxiliaryCarryFlag(),
        ];
    }

    /**
     * @param array<int> $bytes
     * @param array{eax:int,ebx:int} $case
     * @return array{interp: array<string,int|bool>, pat: array<string,int|bool>}
     */
    private function runInterpreterAndPattern(int $ip, array $bytes, array $case): array
    {
        $pattern = new ShrdShlPattern();
        $compiled = $pattern->tryCompile($ip, $bytes);
        $this->assertIsCallable($compiled);

        // -------------
        // Interpreter path
        // -------------
        ['runtime' => $rt1, 'executor' => $ex1] = $this->newRuntimeWithRealExecutor();
        $this->initFlatProtected32($rt1);
        $rt1->context()->cpu()->iteration()->setIterate(static fn () => null);

        $rt1->memory()->setOffset($ip);
        $rt1->memory()->write(implode('', array_map('chr', $bytes)));
        $rt1->memory()->setOffset($ip);

        $rt1->setRegister(RegisterType::EAX, $case['eax'], 32);
        $rt1->setRegister(RegisterType::EBX, $case['ebx'], 32);

        // Seed AF so we can verify it is preserved (SHRD/SHL/ROL do not define AF).
        $rt1->memoryAccessor()->setAuxiliaryCarryFlag(true);
        $rt1->memoryAccessor()->setParityFlag(false);
        $rt1->memoryAccessor()->setZeroFlag(true);
        $rt1->memoryAccessor()->setSignFlag(true);

        $ex1->execute($rt1);
        $rt1->context()->cpu()->clearTransientOverrides();
        $ex1->execute($rt1);
        $rt1->context()->cpu()->clearTransientOverrides();

        $interp = $this->snapshotState($rt1);

        // -------------
        // Pattern path
        // -------------
        ['runtime' => $rt2] = $this->newRuntimeWithRealExecutor();
        $this->initFlatProtected32($rt2);

        $rt2->memory()->setOffset($ip);
        $rt2->memory()->write(implode('', array_map('chr', $bytes)));
        $rt2->memory()->setOffset($ip);

        $rt2->setRegister(RegisterType::EAX, $case['eax'], 32);
        $rt2->setRegister(RegisterType::EBX, $case['ebx'], 32);

        $rt2->memoryAccessor()->setAuxiliaryCarryFlag(true);
        $rt2->memoryAccessor()->setParityFlag(false);
        $rt2->memoryAccessor()->setZeroFlag(true);
        $rt2->memoryAccessor()->setSignFlag(true);

        $result = $compiled($rt2);
        $this->assertTrue($result->isSuccess());

        $pat = $this->snapshotState($rt2);

        return ['interp' => $interp, 'pat' => $pat];
    }

    public function testShlVariantMatchesInterpreter(): void
    {
        $ip = 0x2000;
        // SHRD EAX, EBX, 4; SHL EAX, 1
        $bytes = [0x0F, 0xAC, 0xD8, 0x04, 0xD1, 0xE0];

        $cases = [
            ['eax' => 0x12345678, 'ebx' => 0x9ABCDEF0],
            ['eax' => 0x00000000, 'ebx' => 0xFFFFFFFF],
            ['eax' => 0xFFFFFFFF, 'ebx' => 0x00000000],
        ];

        foreach ($cases as $case) {
            ['interp' => $interp, 'pat' => $pat] = $this->runInterpreterAndPattern($ip, $bytes, $case);
            $this->assertSame($interp, $pat, sprintf(
                'Mismatch for SHL case: eax=0x%08X ebx=0x%08X',
                $case['eax'],
                $case['ebx'],
            ));
        }
    }

    public function testRolVariantMatchesInterpreter(): void
    {
        $ip = 0x3000;
        // SHRD EAX, EBX, 4; ROL EAX, 1
        $bytes = [0x0F, 0xAC, 0xD8, 0x04, 0xD1, 0xC0];

        $cases = [
            ['eax' => 0x12345678, 'ebx' => 0x9ABCDEF0],
            ['eax' => 0x00000001, 'ebx' => 0x00000000],
            ['eax' => 0x80000000, 'ebx' => 0x00000001],
        ];

        foreach ($cases as $case) {
            ['interp' => $interp, 'pat' => $pat] = $this->runInterpreterAndPattern($ip, $bytes, $case);
            $this->assertSame($interp, $pat, sprintf(
                'Mismatch for ROL case: eax=0x%08X ebx=0x%08X',
                $case['eax'],
                $case['ebx'],
            ));
        }
    }
}
