<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\PatternedInstruction;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Instruction\Intel\InstructionExecutor;
use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\MemsetDwordLoopPattern;
use PHPMachineEmulator\Instruction\Intel\x86_64;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestArchitectureProvider;
use Tests\Utils\TestRuntime;

final class MemsetDwordLoopPatternEquivalenceTest extends TestCase
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
     * @return array<string,int|bool|string>
     */
    private function snapshot(TestRuntime $runtime, int $dst, int $count): array
    {
        $ma = $runtime->memoryAccessor();
        $saved = $runtime->memory()->offset();
        $runtime->memory()->setOffset($dst);
        $bytes = $runtime->memory()->read($count);
        $runtime->memory()->setOffset($saved);

        return [
            'ip' => $runtime->memory()->offset() & 0xFFFFFFFF,
            'eax' => $runtime->getRegister(RegisterType::EAX, 32) & 0xFFFFFFFF,
            'ecx' => $runtime->getRegister(RegisterType::ECX, 32) & 0xFFFFFFFF,
            'edx' => $runtime->getRegister(RegisterType::EDX, 32) & 0xFFFFFFFF,
            'mem_sha1' => sha1($bytes),
            'cf' => $ma->shouldCarryFlag(),
            'zf' => $ma->shouldZeroFlag(),
            'sf' => $ma->shouldSignFlag(),
            'of' => $ma->shouldOverflowFlag(),
            'pf' => $ma->shouldParityFlag(),
            'af' => $ma->shouldAuxiliaryCarryFlag(),
        ];
    }

    public function testPatternMatchesInterpreterForSmallCountsBelowDetectionThreshold(): void
    {
        $ip = 0x1000;
        $epilogueIp = ($ip + 48) & 0xFFFFFFFF;

        $bytes = [
            0x89, 0xF7, 0x29, 0xD7, 0x83, 0xFF, 0x03, 0x77, 0x11, 0x89, 0xCA, 0xC1, 0xEA, 0x02, 0x6B, 0xFA,
            0xFC, 0x01, 0xF9, 0x8D, 0x04, 0x90, 0x01, 0xC1, 0xEB, 0x0A, 0x8B, 0x7D, 0xEC, 0x89, 0x3A, 0x83,
            0xC2, 0x04, 0xEB, 0xDC, 0x39, 0xC8, 0x74, 0x08, 0x8A, 0x55, 0xF3, 0x88, 0x10, 0x40, 0xEB, 0xF4,
            0x89, 0xD8, 0x5A, 0x59, 0x5B, 0x5E, 0x5F, 0x5D, 0xC3,
        ];

        $pattern = new MemsetDwordLoopPattern();
        $compiled = $pattern->tryCompile($ip, $bytes);
        $this->assertIsCallable($compiled);

        $dst = 0x2000;
        $ebp = 0x3000;

        $fillDword = 0x11223344;
        $fillByte = 0x5A;

        // Keep counts small so the interpreter path doesn't hit PatternedInstructionsList DETECTION_THRESHOLD=10.
        $cases = [0, 3, 4, 30, 32];

        foreach ($cases as $count) {
            // -------------
            // Interpreter path
            // -------------
            ['runtime' => $rt1, 'executor' => $ex1] = $this->newRuntimeWithRealExecutor();
            $this->initFlatProtected32($rt1);

            // Avoid translation blocks; also keep instruction count below the pattern detection threshold.
            $rt1->context()->cpu()->iteration()->setIterate(static fn () => null);

            $rt1->memory()->setOffset($ip);
            $rt1->memory()->write(implode('', array_map('chr', $bytes)));

            // Fill destination with a sentinel value first.
            $rt1->memory()->setOffset($dst);
            $rt1->memory()->write(str_repeat("\xAA", $count));

            $rt1->setRegister(RegisterType::EAX, $dst, 32);
            $rt1->setRegister(RegisterType::EBX, 0xDEADBEEF, 32);
            $rt1->setRegister(RegisterType::ECX, $count, 32);
            $rt1->setRegister(RegisterType::EDX, $dst, 32);
            $rt1->setRegister(RegisterType::ESI, ($dst + $count) & 0xFFFFFFFF, 32);
            $rt1->setRegister(RegisterType::EBP, $ebp, 32);

            // Fill values are stored on the stack frame in the matched routine.
            $rt1->writeMemory(($ebp - 0x14) & 0xFFFFFFFF, $fillDword, 32);
            $rt1->writeMemory(($ebp - 0x0D) & 0xFFFFFFFF, $fillByte, 8);

            // Seed flags with non-defaults to ensure we match the exit `cmp` flags.
            $rt1->memoryAccessor()->setCarryFlag(true);
            $rt1->memoryAccessor()->setZeroFlag(false);
            $rt1->memoryAccessor()->setSignFlag(true);
            $rt1->memoryAccessor()->setOverflowFlag(true);
            $rt1->memoryAccessor()->setParityFlag(false);
            $rt1->memoryAccessor()->setAuxiliaryCarryFlag(true);

            $rt1->memory()->setOffset($ip);
            $steps = 0;
            while (($rt1->memory()->offset() & 0xFFFFFFFF) !== $epilogueIp && $steps < 2000) {
                $ex1->execute($rt1);
                $rt1->context()->cpu()->clearTransientOverrides();
                $steps++;
            }
            $this->assertSame($epilogueIp, $rt1->memory()->offset(), 'Interpreter did not reach epilogue');
            $interp = $this->snapshot($rt1, $dst, $count);

            // -------------
            // Pattern path
            // -------------
            ['runtime' => $rt2] = $this->newRuntimeWithRealExecutor();
            $this->initFlatProtected32($rt2);

            $rt2->memory()->setOffset($ip);
            $rt2->memory()->write(implode('', array_map('chr', $bytes)));

            $rt2->memory()->setOffset($dst);
            $rt2->memory()->write(str_repeat("\xAA", $count));

            $rt2->setRegister(RegisterType::EAX, $dst, 32);
            $rt2->setRegister(RegisterType::EBX, 0xDEADBEEF, 32);
            $rt2->setRegister(RegisterType::ECX, $count, 32);
            $rt2->setRegister(RegisterType::EDX, $dst, 32);
            $rt2->setRegister(RegisterType::ESI, ($dst + $count) & 0xFFFFFFFF, 32);
            $rt2->setRegister(RegisterType::EBP, $ebp, 32);

            $rt2->writeMemory(($ebp - 0x14) & 0xFFFFFFFF, $fillDword, 32);
            $rt2->writeMemory(($ebp - 0x0D) & 0xFFFFFFFF, $fillByte, 8);

            $rt2->memoryAccessor()->setCarryFlag(true);
            $rt2->memoryAccessor()->setZeroFlag(false);
            $rt2->memoryAccessor()->setSignFlag(true);
            $rt2->memoryAccessor()->setOverflowFlag(true);
            $rt2->memoryAccessor()->setParityFlag(false);
            $rt2->memoryAccessor()->setAuxiliaryCarryFlag(true);

            $rt2->memory()->setOffset($ip);
            $result = $compiled($rt2);
            $this->assertTrue($result->isSuccess());

            $pat = $this->snapshot($rt2, $dst, $count);

            $this->assertSame($interp, $pat, sprintf('Mismatch for count=%d', $count));
        }
    }
}

