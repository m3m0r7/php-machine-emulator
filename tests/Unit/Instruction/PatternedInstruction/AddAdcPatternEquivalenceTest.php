<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\PatternedInstruction;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Instruction\Intel\InstructionExecutor;
use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\AddAdcPattern;
use PHPMachineEmulator\Instruction\Intel\x86_64;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestArchitectureProvider;
use Tests\Utils\TestRuntime;

final class AddAdcPatternEquivalenceTest extends TestCase
{
    /**
     * @return array{runtime: TestRuntime, list: x86_64, executor: InstructionExecutor}
     */
    private function newRuntimeWithRealExecutor(): array
    {
        $instructionList = new x86_64();
        $executor = new InstructionExecutor();

        $arch = new class($instructionList, $executor) extends TestArchitectureProvider {
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

        $runtime = new class($arch) extends TestRuntime {
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

        // Minimal flat GDT: null, code (0x08), data (0x10).
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
            'ecx' => $runtime->getRegister(RegisterType::ECX, 32) & 0xFFFFFFFF,
            'edx' => $runtime->getRegister(RegisterType::EDX, 32) & 0xFFFFFFFF,
            'cf' => $ma->shouldCarryFlag(),
            'zf' => $ma->shouldZeroFlag(),
            'sf' => $ma->shouldSignFlag(),
            'of' => $ma->shouldOverflowFlag(),
            'pf' => $ma->shouldParityFlag(),
            'af' => $ma->shouldAuxiliaryCarryFlag(),
        ];
    }

    public function testPatternMatchesInterpreterForRepresentativeCases(): void
    {
        $ip = 0x1000;

        // ADD EAX, EBX; ADC EDX, ECX (both register-register)
        $bytes = [0x01, 0xD8, 0x11, 0xCA];

        $pattern = new AddAdcPattern();
        $compiled = $pattern->tryCompile($ip, $bytes);
        $this->assertIsCallable($compiled);

        $cases = [
            // No carry anywhere -> ZF=1 PF=1 AF=0
            ['eax' => 0x00000000, 'ebx' => 0x00000000, 'edx' => 0x00000000, 'ecx' => 0x00000000],
            // Carry from ADD feeds ADC, parity changes
            ['eax' => 0xFFFFFFFF, 'ebx' => 0x00000001, 'edx' => 0x00000000, 'ecx' => 0x00000000],
            // ADC sets AF via low-nibble carry-in
            ['eax' => 0xFFFFFFFF, 'ebx' => 0x00000001, 'edx' => 0x0000000F, 'ecx' => 0x00000000],
            // ADC overflow should consider carry-in (src + carry can flip sign)
            ['eax' => 0xFFFFFFFF, 'ebx' => 0x00000001, 'edx' => 0x7FFFFFFF, 'ecx' => 0x7FFFFFFF],
        ];

        foreach ($cases as $case) {
            // -------------
            // Interpreter path
            // -------------
            ['runtime' => $rt1, 'executor' => $ex1] = $this->newRuntimeWithRealExecutor();
            $this->initFlatProtected32($rt1);

            // Force single-step execution path to avoid TB/pattern effects.
            $rt1->context()->cpu()->iteration()->setIterate(static fn () => null);

            $rt1->memory()->setOffset($ip);
            $rt1->memory()->write(implode('', array_map('chr', $bytes)));
            $rt1->memory()->setOffset($ip);

            $rt1->setRegister(RegisterType::EAX, $case['eax'], 32);
            $rt1->setRegister(RegisterType::EBX, $case['ebx'], 32);
            $rt1->setRegister(RegisterType::EDX, $case['edx'], 32);
            $rt1->setRegister(RegisterType::ECX, $case['ecx'], 32);

            // Seed flags so we can detect pattern leaving PF/AF untouched.
            $rt1->memoryAccessor()->setParityFlag(false);
            $rt1->memoryAccessor()->setAuxiliaryCarryFlag(true);
            $rt1->memoryAccessor()->setCarryFlag(true);
            $rt1->memoryAccessor()->setOverflowFlag(true);

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
            $rt2->setRegister(RegisterType::EDX, $case['edx'], 32);
            $rt2->setRegister(RegisterType::ECX, $case['ecx'], 32);

            $rt2->memoryAccessor()->setParityFlag(false);
            $rt2->memoryAccessor()->setAuxiliaryCarryFlag(true);
            $rt2->memoryAccessor()->setCarryFlag(true);
            $rt2->memoryAccessor()->setOverflowFlag(true);

            $result = $compiled($rt2);
            $this->assertTrue($result->isSuccess());

            $pat = $this->snapshotState($rt2);

            $this->assertSame($interp, $pat, sprintf(
                'Mismatch for case: eax=0x%08X ebx=0x%08X edx=0x%08X ecx=0x%08X',
                $case['eax'],
                $case['ebx'],
                $case['edx'],
                $case['ecx'],
            ));
        }
    }
}

