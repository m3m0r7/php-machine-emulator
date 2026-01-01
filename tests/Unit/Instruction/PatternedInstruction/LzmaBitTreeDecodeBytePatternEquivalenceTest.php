<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\PatternedInstruction;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Instruction\Intel\InstructionExecutor;
use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\LzmaBitTreeDecodeBytePattern;
use PHPMachineEmulator\Instruction\Intel\x86_64;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestArchitectureProvider;
use Tests\Utils\TestRuntime;

final class LzmaBitTreeDecodeBytePatternEquivalenceTest extends TestCase
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
     * @return array<string,int|bool|string>
     */
    private function snapshotState(TestRuntime $runtime, int $probBase, int $probLen, int $rangeAddr, int $codeAddr): array
    {
        $ma = $runtime->memoryAccessor();

        $saved = $runtime->memory()->offset();
        $runtime->memory()->setOffset($probBase);
        $probBytes = $runtime->memory()->read($probLen);
        $runtime->memory()->setOffset($saved);

        return [
            'ip' => $runtime->memory()->offset() & 0xFFFFFFFF,
            'eax' => $runtime->getRegister(RegisterType::EAX, 32) & 0xFFFFFFFF,
            'ecx' => $runtime->getRegister(RegisterType::ECX, 32) & 0xFFFFFFFF,
            'edx' => $runtime->getRegister(RegisterType::EDX, 32) & 0xFFFFFFFF,
            'esi' => $runtime->getRegister(RegisterType::ESI, 32) & 0xFFFFFFFF,
            'esp' => $runtime->getRegister(RegisterType::ESP, 32) & 0xFFFFFFFF,
            'range' => $runtime->readMemory($rangeAddr, 32) & 0xFFFFFFFF,
            'code' => $runtime->readMemory($codeAddr, 32) & 0xFFFFFFFF,
            'prob_sha1' => sha1($probBytes),
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
        $ip = 0x8B73;
        $exitIp = 0x8B8C;

        $loopBytes = [
            0x81, 0xFA, 0x00, 0x01, 0x00, 0x00, // cmp edx, 0x100
            0x73, 0x11,                         // jnc 0x8B8C
            0x52,                               // push edx
            0x89, 0xD0,                         // mov eax, edx
            0x03, 0x44, 0x24, 0x08,             // add eax, [esp+8]
            0xE8, 0x66, 0xFE, 0xFF, 0xFF,       // call 0x89ED
            0x5A,                               // pop edx
            0x11, 0xD2,                         // adc edx, edx
            0xEB, 0xE7,                         // jmp 0x8B73
        ];

        $decodeBitBytes = [
            0x8D, 0x04, 0x83, 0x89, 0xC1, 0x8B, 0x01, 0x8B, 0x55, 0xF4, 0xC1, 0xEA, 0x0B, 0xF7, 0xE2,
            0x3B, 0x45, 0xF0, 0x76, 0x28, 0x89, 0x45, 0xF4, 0xBA, 0x00, 0x08, 0x00, 0x00, 0x2B, 0x11,
            0xC1, 0xEA, 0x05, 0x01, 0x11, 0xF8, 0x9C, 0x81, 0x7D, 0xF4, 0x00, 0x00, 0x00, 0x01, 0x73,
            0x0C, 0xC1, 0x65, 0xF0, 0x08, 0xAC, 0x88, 0x45, 0xF0, 0xC1, 0x65, 0xF4, 0x08, 0x9D, 0xC3,
            0x29, 0x45, 0xF4, 0x29, 0x45, 0xF0, 0x8B, 0x11, 0xC1, 0xEA, 0x05, 0x29, 0x11, 0xF9, 0xEB,
            0xD8,
        ];

        $pattern = new LzmaBitTreeDecodeBytePattern();
        $compiled = $pattern->tryCompile($ip, $loopBytes);
        $this->assertNotNull($compiled);

        $ebp = 0x8000;
        $rangeAddr = $ebp - 0x0C;
        $codeAddr = $ebp - 0x10;

        $probBase = 0xB000;
        $probLen = 0x800; // 0x200 dwords

        $esi = 0xA000;
        $esp = 0x9000;

        $cases = [
            ['range' => 0x20000000, 'code' => 0x0FFFFFFF, 'byte' => 0xAB, 'baseIndex' => 0],
            ['range' => 0x02000000, 'code' => 0x01C00000, 'byte' => 0x5A, 'baseIndex' => 0],
            ['range' => 0xFFFFFFFF, 'code' => 0x80000000, 'byte' => 0x00, 'baseIndex' => 0x100],
        ];

        foreach ($cases as $case) {
            // -------------
            // Interpreter path (single-step, no patterns/TB)
            // -------------
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

            $gdt = str_repeat("\x00", 8)
                . "\xFF\xFF\x00\x00\x00\x9A\xCF\x00"
                . "\xFF\xFF\x00\x00\x00\x92\xCF\x00";
            $rt1->memory()->setOffset(0x5000);
            $rt1->memory()->write($gdt);

            $rt1->setRegister(RegisterType::CS, 0x0008, 16);
            $rt1->setRegister(RegisterType::DS, 0x0010, 16);
            $rt1->setRegister(RegisterType::SS, 0x0010, 16);

            // Force single-step execution to avoid TB/pattern effects.
            $rt1->context()->cpu()->iteration()->setIterate(static fn () => null);

            // Code
            $rt1->memory()->setOffset(0x89ED);
            $rt1->memory()->write(implode('', array_map('chr', $decodeBitBytes)));
            $rt1->memory()->setOffset($ip);
            $rt1->memory()->write(implode('', array_map('chr', $loopBytes)));

            // Prob array
            $probInit = str_repeat(pack('V', 0x00000400), intdiv($probLen, 4));
            $rt1->memory()->setOffset($probBase);
            $rt1->memory()->write($probInit);

            // Range/code
            $rt1->writeMemory($rangeAddr, $case['range'], 32);
            $rt1->writeMemory($codeAddr, $case['code'], 32);

            // Input bytes for normalization
            $rt1->memory()->setOffset($esi);
            $rt1->memory()->write(str_repeat(chr($case['byte']), 64));

            // Stack base-index at [esp+4]
            $rt1->writeMemory($esp + 4, $case['baseIndex'], 32);

            $rt1->setRegister(RegisterType::EBP, $ebp, 32);
            $rt1->setRegister(RegisterType::EBX, $probBase, 32);
            $rt1->setRegister(RegisterType::ESI, $esi, 32);
            $rt1->setRegister(RegisterType::ESP, $esp, 32);
            $rt1->setRegister(RegisterType::EDX, 1, 32);
            $rt1->setRegister(RegisterType::EAX, 0, 32);
            $rt1->setRegister(RegisterType::ECX, 0, 32);

            $rt1->memory()->setOffset($ip);

            $steps = 0;
            while (($rt1->memory()->offset() & 0xFFFFFFFF) !== $exitIp && $steps < 2000) {
                $ex1->execute($rt1);
                $rt1->context()->cpu()->clearTransientOverrides();
                $steps++;
            }
            $this->assertSame($exitIp, $rt1->memory()->offset(), 'Interpreter did not reach exit IP');

            $interp = $this->snapshotState($rt1, $probBase, $probLen, $rangeAddr, $codeAddr);

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

            $rt2->memory()->setOffset(0x89ED);
            $rt2->memory()->write(implode('', array_map('chr', $decodeBitBytes)));
            $rt2->memory()->setOffset($ip);
            $rt2->memory()->write(implode('', array_map('chr', $loopBytes)));

            $rt2->memory()->setOffset($probBase);
            $rt2->memory()->write($probInit);

            $rt2->writeMemory($rangeAddr, $case['range'], 32);
            $rt2->writeMemory($codeAddr, $case['code'], 32);

            $rt2->memory()->setOffset($esi);
            $rt2->memory()->write(str_repeat(chr($case['byte']), 64));

            $rt2->writeMemory($esp + 4, $case['baseIndex'], 32);

            $rt2->setRegister(RegisterType::EBP, $ebp, 32);
            $rt2->setRegister(RegisterType::EBX, $probBase, 32);
            $rt2->setRegister(RegisterType::ESI, $esi, 32);
            $rt2->setRegister(RegisterType::ESP, $esp, 32);
            $rt2->setRegister(RegisterType::EDX, 1, 32);
            $rt2->setRegister(RegisterType::EAX, 0, 32);
            $rt2->setRegister(RegisterType::ECX, 0, 32);

            $rt2->memory()->setOffset($ip);

            $result = $compiled($rt2);
            $this->assertTrue($result->isSuccess());
            $this->assertSame($exitIp, $rt2->memory()->offset());

            $pat = $this->snapshotState($rt2, $probBase, $probLen, $rangeAddr, $codeAddr);

            $this->assertSame($interp, $pat, sprintf(
                'Mismatch for case: range=0x%08X code=0x%08X byte=0x%02X baseIndex=0x%X',
                $case['range'],
                $case['code'],
                $case['byte'],
                $case['baseIndex'],
            ));
        }
    }
}
