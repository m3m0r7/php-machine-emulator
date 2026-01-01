<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\PatternedInstruction;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Instruction\Intel\InstructionExecutor;
use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\LzmaRangeDecodeBitPattern;
use PHPMachineEmulator\Instruction\Intel\x86_64;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestArchitectureProvider;
use Tests\Utils\TestRuntime;

final class LzmaRangeDecodeBitPatternEquivalenceTest extends TestCase
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

    /**
     * @param array<int> $fnBytes
     * @return array<string,int|bool>
     */
    private function snapshotState(TestRuntime $runtime, int $probPtr, int $rangeAddr, int $codeAddr): array
    {
        $ma = $runtime->memoryAccessor();
        return [
            'ip' => $runtime->memory()->offset() & 0xFFFFFFFF,
            'eax' => $runtime->getRegister(RegisterType::EAX, 32) & 0xFFFFFFFF,
            'ecx' => $runtime->getRegister(RegisterType::ECX, 32) & 0xFFFFFFFF,
            'edx' => $runtime->getRegister(RegisterType::EDX, 32) & 0xFFFFFFFF,
            'esi' => $runtime->getRegister(RegisterType::ESI, 32) & 0xFFFFFFFF,
            'esp' => $runtime->getRegister(RegisterType::ESP, 32) & 0xFFFFFFFF,
            'prob' => $runtime->readMemory($probPtr, 32) & 0xFFFFFFFF,
            'range' => $runtime->readMemory($rangeAddr, 32) & 0xFFFFFFFF,
            'code' => $runtime->readMemory($codeAddr, 32) & 0xFFFFFFFF,
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
        $ip = 0x89ED;
        $returnIp = 0x2000;
        $ebp = 0x8000;
        $esi = 0xA000;
        $esp = 0x9000;
        $probPtr = 0xB000;

        $rangeAddr = $ebp - 0x0C;
        $codeAddr = $ebp - 0x10;

        // Full decode-bit routine bytes (0x89ED..0x8A38), needed for both branches.
        $fnBytes = [
            0x8D, 0x04, 0x83, 0x89, 0xC1, 0x8B, 0x01, 0x8B, 0x55, 0xF4, 0xC1, 0xEA, 0x0B, 0xF7, 0xE2,
            0x3B, 0x45, 0xF0, 0x76, 0x28, 0x89, 0x45, 0xF4, 0xBA, 0x00, 0x08, 0x00, 0x00, 0x2B, 0x11,
            0xC1, 0xEA, 0x05, 0x01, 0x11, 0xF8, 0x9C, 0x81, 0x7D, 0xF4, 0x00, 0x00, 0x00, 0x01, 0x73,
            0x0C, 0xC1, 0x65, 0xF0, 0x08, 0xAC, 0x88, 0x45, 0xF0, 0xC1, 0x65, 0xF4, 0x08, 0x9D, 0xC3,
            0x29, 0x45, 0xF4, 0x29, 0x45, 0xF0, 0x8B, 0x11, 0xC1, 0xEA, 0x05, 0x29, 0x11, 0xF9, 0xEB,
            0xD8,
        ];

        $cases = [
            // bit=0, no normalization
            ['range' => 0x20000000, 'prob' => 0x00000400, 'code' => 0x0FFFFFFF, 'byte' => 0xAB],
            // bit=0, normalization
            ['range' => 0x00800000, 'prob' => 0x00000400, 'code' => 0x00000000, 'byte' => 0xAB],
            // bit=1, no normalization (code >= bound)
            ['range' => 0x20000000, 'prob' => 0x00000400, 'code' => 0x10000000, 'byte' => 0xAB],
            // bit=1, no normalization with high-bit code (exercises unsigned compare correctness)
            ['range' => 0xFFFFFFFF, 'prob' => 0x00000001, 'code' => 0x80000000, 'byte' => 0xAB],
            // bit=1, normalization
            ['range' => 0x02000000, 'prob' => 0x00000700, 'code' => 0x01C00000, 'byte' => 0xAB],
        ];

        $pattern = new LzmaRangeDecodeBitPattern();
        $compiled = $pattern->tryCompile($ip, array_slice($fnBytes, 0, 64));
        $this->assertNotNull($compiled);

        foreach ($cases as $case) {
            // -----------------
            // Interpreter path
            // -----------------
            ['runtime' => $rt1, 'executor' => $ex1] = $this->newRuntimeWithRealExecutor();

            $rt1->cpuContext()->enableA20(true);
            $rt1->cpuContext()->setPagingEnabled(false);
            $rt1->cpuContext()->setProtectedMode(true);
            $rt1->cpuContext()->setDefaultOperandSize(32);
            $rt1->cpuContext()->setDefaultAddressSize(32);
            $rt1->cpuContext()->cacheSegmentDescriptor(RegisterType::CS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true]);
            $rt1->cpuContext()->cacheSegmentDescriptor(RegisterType::DS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true]);
            $rt1->cpuContext()->cacheSegmentDescriptor(RegisterType::SS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true]);
            $rt1->cpuContext()->setGdtr(0x5000, 0x17);

            // Minimal flat GDT: null, code (0x08), data (0x10).
            $gdt = str_repeat("\x00", 8)
                . "\xFF\xFF\x00\x00\x00\x9A\xCF\x00"
                . "\xFF\xFF\x00\x00\x00\x92\xCF\x00";
            $rt1->memory()->setOffset(0x5000);
            $rt1->memory()->write($gdt);

            $rt1->setRegister(RegisterType::CS, 0x0008, 16);
            $rt1->setRegister(RegisterType::DS, 0x0010, 16);
            $rt1->setRegister(RegisterType::SS, 0x0010, 16);

            // Force single-step execution path to avoid TB/pattern effects.
            $rt1->context()->cpu()->iteration()->setIterate(static fn () => null);

            $rt1->memory()->setOffset($ip);
            $rt1->memory()->write(implode('', array_map('chr', $fnBytes)));
            $rt1->writeMemory($probPtr, $case['prob'], 32);
            $rt1->writeMemory($rangeAddr, $case['range'], 32);
            $rt1->writeMemory($codeAddr, $case['code'], 32);
            $rt1->writeMemory($esi, $case['byte'], 8);
            $rt1->writeMemory($esp, $returnIp, 32);

            $rt1->setRegister(RegisterType::EBP, $ebp, 32);
            $rt1->setRegister(RegisterType::EBX, $probPtr, 32);
            $rt1->setRegister(RegisterType::EAX, 0, 32); // index
            $rt1->setRegister(RegisterType::ESI, $esi, 32);
            $rt1->setRegister(RegisterType::ESP, $esp, 32);
            $rt1->memory()->setOffset($ip);

            $steps = 0;
            while (($rt1->memory()->offset() & 0xFFFFFFFF) !== $returnIp && $steps < 200) {
                $ex1->execute($rt1);
                $rt1->context()->cpu()->clearTransientOverrides();
                $steps++;
            }
            $this->assertSame($returnIp, $rt1->memory()->offset());

            $interp = $this->snapshotState($rt1, $probPtr, $rangeAddr, $codeAddr);

            // -------------
            // Pattern path
            // -------------
            ['runtime' => $rt2] = $this->newRuntimeWithRealExecutor();

            $rt2->cpuContext()->enableA20(true);
            $rt2->cpuContext()->setPagingEnabled(false);
            $rt2->cpuContext()->setProtectedMode(true);
            $rt2->cpuContext()->setDefaultOperandSize(32);
            $rt2->cpuContext()->setDefaultAddressSize(32);
            $rt2->cpuContext()->cacheSegmentDescriptor(RegisterType::CS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true]);
            $rt2->cpuContext()->cacheSegmentDescriptor(RegisterType::DS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true]);
            $rt2->cpuContext()->cacheSegmentDescriptor(RegisterType::SS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true]);
            $rt2->cpuContext()->setGdtr(0x5000, 0x17);

            $rt2->memory()->setOffset(0x5000);
            $rt2->memory()->write($gdt);

            $rt2->setRegister(RegisterType::CS, 0x0008, 16);
            $rt2->setRegister(RegisterType::DS, 0x0010, 16);
            $rt2->setRegister(RegisterType::SS, 0x0010, 16);

            $rt2->memory()->setOffset($ip);
            $rt2->memory()->write(implode('', array_map('chr', $fnBytes)));
            $rt2->writeMemory($probPtr, $case['prob'], 32);
            $rt2->writeMemory($rangeAddr, $case['range'], 32);
            $rt2->writeMemory($codeAddr, $case['code'], 32);
            $rt2->writeMemory($esi, $case['byte'], 8);
            $rt2->writeMemory($esp, $returnIp, 32);

            $rt2->setRegister(RegisterType::EBP, $ebp, 32);
            $rt2->setRegister(RegisterType::EBX, $probPtr, 32);
            $rt2->setRegister(RegisterType::EAX, 0, 32); // index
            $rt2->setRegister(RegisterType::ESI, $esi, 32);
            $rt2->setRegister(RegisterType::ESP, $esp, 32);
            $rt2->memory()->setOffset($ip);

            $result = $compiled($rt2);
            $this->assertTrue($result->isSuccess());

            $pat = $this->snapshotState($rt2, $probPtr, $rangeAddr, $codeAddr);

            $this->assertSame($interp, $pat, sprintf(
                'Mismatch for case: range=0x%08X prob=0x%08X code=0x%08X byte=0x%02X',
                $case['range'],
                $case['prob'],
                $case['code'],
                $case['byte'],
            ));
        }
    }

    public function testPatternDoesNotRequireSegmentDescriptorCache(): void
    {
        $ip = 0x89ED;
        $returnIp = 0x2000;
        $ebp = 0x8000;
        $esi = 0xA000;
        $esp = 0x9000;
        $probPtr = 0xB000;

        $rangeAddr = $ebp - 0x0C;
        $codeAddr = $ebp - 0x10;

        $fnBytes = [
            0x8D, 0x04, 0x83, 0x89, 0xC1, 0x8B, 0x01, 0x8B, 0x55, 0xF4, 0xC1, 0xEA, 0x0B, 0xF7, 0xE2,
            0x3B, 0x45, 0xF0, 0x76, 0x28, 0x89, 0x45, 0xF4, 0xBA, 0x00, 0x08, 0x00, 0x00, 0x2B, 0x11,
            0xC1, 0xEA, 0x05, 0x01, 0x11, 0xF8, 0x9C, 0x81, 0x7D, 0xF4, 0x00, 0x00, 0x00, 0x01, 0x73,
            0x0C, 0xC1, 0x65, 0xF0, 0x08, 0xAC, 0x88, 0x45, 0xF0, 0xC1, 0x65, 0xF4, 0x08, 0x9D, 0xC3,
            0x29, 0x45, 0xF4, 0x29, 0x45, 0xF0, 0x8B, 0x11, 0xC1, 0xEA, 0x05, 0x29, 0x11, 0xF9, 0xEB,
            0xD8,
        ];

        $pattern = new LzmaRangeDecodeBitPattern();
        $compiled = $pattern->tryCompile($ip, array_slice($fnBytes, 0, 64));
        $this->assertNotNull($compiled);

        $case = ['range' => 0x00800000, 'prob' => 0x00000400, 'code' => 0x00000000, 'byte' => 0xAB];

        // -----------------
        // Interpreter path
        // -----------------
        ['runtime' => $rt1, 'executor' => $ex1] = $this->newRuntimeWithRealExecutor();

        $rt1->cpuContext()->enableA20(true);
        $rt1->cpuContext()->setPagingEnabled(false);
        $rt1->cpuContext()->setProtectedMode(true);
        $rt1->cpuContext()->setDefaultOperandSize(32);
        $rt1->cpuContext()->setDefaultAddressSize(32);
        $rt1->cpuContext()->setGdtr(0x5000, 0x17);

        // Minimal flat GDT: null, code (0x08), data (0x10).
        $gdt = str_repeat("\x00", 8)
            . "\xFF\xFF\x00\x00\x00\x9A\xCF\x00"
            . "\xFF\xFF\x00\x00\x00\x92\xCF\x00";
        $rt1->memory()->setOffset(0x5000);
        $rt1->memory()->write($gdt);

        $rt1->setRegister(RegisterType::CS, 0x0008, 16);
        $rt1->setRegister(RegisterType::DS, 0x0010, 16);
        $rt1->setRegister(RegisterType::SS, 0x0010, 16);

        // Force single-step execution path to avoid TB/pattern effects.
        $rt1->context()->cpu()->iteration()->setIterate(static fn () => null);

        $rt1->memory()->setOffset($ip);
        $rt1->memory()->write(implode('', array_map('chr', $fnBytes)));
        $rt1->writeMemory($probPtr, $case['prob'], 32);
        $rt1->writeMemory($rangeAddr, $case['range'], 32);
        $rt1->writeMemory($codeAddr, $case['code'], 32);
        $rt1->writeMemory($esi, $case['byte'], 8);
        $rt1->writeMemory($esp, $returnIp, 32);

        $rt1->setRegister(RegisterType::EBP, $ebp, 32);
        $rt1->setRegister(RegisterType::EBX, $probPtr, 32);
        $rt1->setRegister(RegisterType::EAX, 0, 32); // index
        $rt1->setRegister(RegisterType::ESI, $esi, 32);
        $rt1->setRegister(RegisterType::ESP, $esp, 32);
        $rt1->memory()->setOffset($ip);

        $steps = 0;
        while (($rt1->memory()->offset() & 0xFFFFFFFF) !== $returnIp && $steps < 200) {
            $ex1->execute($rt1);
            $rt1->context()->cpu()->clearTransientOverrides();
            $steps++;
        }
        $this->assertSame($returnIp, $rt1->memory()->offset());

        $interp = $this->snapshotState($rt1, $probPtr, $rangeAddr, $codeAddr);

        // -------------
        // Pattern path
        // -------------
        ['runtime' => $rt2] = $this->newRuntimeWithRealExecutor();

        $rt2->cpuContext()->enableA20(true);
        $rt2->cpuContext()->setPagingEnabled(false);
        $rt2->cpuContext()->setProtectedMode(true);
        $rt2->cpuContext()->setDefaultOperandSize(32);
        $rt2->cpuContext()->setDefaultAddressSize(32);
        $rt2->cpuContext()->setGdtr(0x5000, 0x17);

        $rt2->memory()->setOffset(0x5000);
        $rt2->memory()->write($gdt);

        $rt2->setRegister(RegisterType::CS, 0x0008, 16);
        $rt2->setRegister(RegisterType::DS, 0x0010, 16);
        $rt2->setRegister(RegisterType::SS, 0x0010, 16);

        $rt2->memory()->setOffset($ip);
        $rt2->memory()->write(implode('', array_map('chr', $fnBytes)));
        $rt2->writeMemory($probPtr, $case['prob'], 32);
        $rt2->writeMemory($rangeAddr, $case['range'], 32);
        $rt2->writeMemory($codeAddr, $case['code'], 32);
        $rt2->writeMemory($esi, $case['byte'], 8);
        $rt2->writeMemory($esp, $returnIp, 32);

        $rt2->setRegister(RegisterType::EBP, $ebp, 32);
        $rt2->setRegister(RegisterType::EBX, $probPtr, 32);
        $rt2->setRegister(RegisterType::EAX, 0, 32); // index
        $rt2->setRegister(RegisterType::ESI, $esi, 32);
        $rt2->setRegister(RegisterType::ESP, $esp, 32);
        $rt2->memory()->setOffset($ip);

        $result = $compiled($rt2);
        $this->assertTrue($result->isSuccess());

        $pat = $this->snapshotState($rt2, $probPtr, $rangeAddr, $codeAddr);

        $this->assertSame($interp, $pat);
    }
}
