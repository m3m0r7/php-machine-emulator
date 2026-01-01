<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction\PatternedInstruction;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Instruction\Intel\InstructionExecutor;
use PHPMachineEmulator\Instruction\Intel\PatternedInstruction\LzmaLiteralDecodeMatchPattern;
use PHPMachineEmulator\Instruction\Intel\x86_64;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestArchitectureProvider;
use Tests\Utils\TestRuntime;

final class LzmaLiteralDecodeMatchPatternEquivalenceTest extends TestCase
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
    private function snapshotState(TestRuntime $runtime, int $probBase, int $probLen, int $stackAddr, int $rangeAddr, int $codeAddr): array
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
            'match_byte' => $runtime->readMemory($stackAddr, 8) & 0xFF,
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
        $ip = 0x8B44;
        $exitIp = 0x8B8C;

        $decodeBitBytes = [
            0x8D, 0x04, 0x83, 0x89, 0xC1, 0x8B, 0x01, 0x8B, 0x55, 0xF4, 0xC1, 0xEA, 0x0B, 0xF7, 0xE2,
            0x3B, 0x45, 0xF0, 0x76, 0x28, 0x89, 0x45, 0xF4, 0xBA, 0x00, 0x08, 0x00, 0x00, 0x2B, 0x11,
            0xC1, 0xEA, 0x05, 0x01, 0x11, 0xF8, 0x9C, 0x81, 0x7D, 0xF4, 0x00, 0x00, 0x00, 0x01, 0x73,
            0x0C, 0xC1, 0x65, 0xF0, 0x08, 0xAC, 0x88, 0x45, 0xF0, 0xC1, 0x65, 0xF4, 0x08, 0x9D, 0xC3,
            0x29, 0x45, 0xF4, 0x29, 0x45, 0xF0, 0x8B, 0x11, 0xC1, 0xEA, 0x05, 0x29, 0x11, 0xF9, 0xEB,
            0xD8,
        ];

        // Bytes for 0x8B44..0x8B8B (exit is 0x8B8C).
        $literalBytes = [
            // 8B44: match loop
            0x81, 0xFA, 0x00, 0x01, 0x00, 0x00, 0x73, 0x40, 0x31, 0xC0, 0xD0, 0x24, 0x24, 0x11, 0xC0, 0x50,
            0x52, 0xC1, 0xE0, 0x08, 0x8D, 0x84, 0x02, 0x00, 0x01, 0x00, 0x00, 0x03, 0x44, 0x24, 0x0C, 0xE8,
            0x85, 0xFE, 0xFF, 0xFF, 0x0F, 0x92, 0xC0, 0x5A, 0x11, 0xD2, 0x59, 0x38, 0xC8, 0x74, 0xD1,
            // 8B73: normal loop
            0x81, 0xFA, 0x00, 0x01, 0x00, 0x00, 0x73, 0x11, 0x52, 0x89, 0xD0, 0x03, 0x44, 0x24, 0x08, 0xE8,
            0x66, 0xFE, 0xFF, 0xFF, 0x5A, 0x11, 0xD2, 0xEB, 0xE7,
        ];

        $pattern = new LzmaLiteralDecodeMatchPattern();
        $compiled = $pattern->tryCompile($ip, array_slice($literalBytes, 0, 64));
        $this->assertNotNull($compiled);

        $probBase = 0xB000;
        $probLen = 0x4000;

        $ebp = 0x8000;
        $rangeAddr = $ebp - 0x0C;
        $codeAddr = $ebp - 0x10;

        $esi = 0xA000;
        $esp = 0x9000;
        $stackAddr = $esp;

        $baseIndex = 0x00000736;

        $cases = [
            // Fully matching path: matchByte=0, decoded bits stay 0 => match loop completes.
            ['matchByte' => 0x00],
            // Early mismatch path: matchByte=0xFF, decoded bits stay 0 => mismatch then normal loop.
            ['matchByte' => 0xFF],
        ];

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
            $rt1->cpuContext()->cacheSegmentDescriptor(RegisterType::CS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true, 'default' => 32]);
            $rt1->cpuContext()->cacheSegmentDescriptor(RegisterType::DS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true, 'default' => 32]);
            $rt1->cpuContext()->cacheSegmentDescriptor(RegisterType::SS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true, 'default' => 32]);
            $rt1->cpuContext()->setGdtr(0x5000, 0x17);

            $gdt = str_repeat("\x00", 8)
                . "\xFF\xFF\x00\x00\x00\x9A\xCF\x00"
                . "\xFF\xFF\x00\x00\x00\x92\xCF\x00";
            $rt1->memory()->setOffset(0x5000);
            $rt1->memory()->write($gdt);

            $rt1->setRegister(RegisterType::CS, 0x0008, 16);
            $rt1->setRegister(RegisterType::DS, 0x0010, 16);
            $rt1->setRegister(RegisterType::SS, 0x0010, 16);

            // Force single-step to avoid TB/pattern interference.
            $rt1->context()->cpu()->iteration()->setIterate(static fn () => null);

            $rt1->memory()->setOffset(0x89ED);
            $rt1->memory()->write(implode('', array_map('chr', $decodeBitBytes)));
            $rt1->memory()->setOffset($ip);
            $rt1->memory()->write(implode('', array_map('chr', $literalBytes)));

            $rt1->memory()->setOffset($probBase);
            $rt1->memory()->write(str_repeat(pack('V', 0x00000400), intdiv($probLen, 4)));

            $rt1->writeMemory($rangeAddr, 0x20000000, 32);
            $rt1->writeMemory($codeAddr, 0x00000000, 32);

            $rt1->memory()->setOffset($esi);
            $rt1->memory()->write(str_repeat("\x00", 256));

            $rt1->writeMemory($stackAddr, $case['matchByte'], 32);
            $rt1->writeMemory($stackAddr + 4, $baseIndex, 32);

            $rt1->setRegister(RegisterType::EBP, $ebp, 32);
            $rt1->setRegister(RegisterType::EBX, $probBase, 32);
            $rt1->setRegister(RegisterType::ESI, $esi, 32);
            $rt1->setRegister(RegisterType::ESP, $esp, 32);
            $rt1->setRegister(RegisterType::EDX, 1, 32);
            $rt1->setRegister(RegisterType::EAX, 0, 32);
            $rt1->setRegister(RegisterType::ECX, 0, 32);

            $rt1->memory()->setOffset($ip);

            $steps = 0;
            while (($rt1->memory()->offset() & 0xFFFFFFFF) !== $exitIp && $steps < 20000) {
                $ex1->execute($rt1);
                $rt1->context()->cpu()->clearTransientOverrides();
                $steps++;
            }
            $this->assertSame($exitIp, $rt1->memory()->offset(), 'Interpreter did not reach exit IP');

            $interp = $this->snapshotState($rt1, $probBase, $probLen, $stackAddr, $rangeAddr, $codeAddr);

            // -------------
            // Pattern path
            // -------------
            ['runtime' => $rt2] = $this->newRuntimeWithRealExecutor();

            $rt2->cpuContext()->enableA20(true);
            $rt2->cpuContext()->setPagingEnabled(false);
            $rt2->cpuContext()->setProtectedMode(true);
            $rt2->cpuContext()->setDefaultOperandSize(32);
            $rt2->cpuContext()->setDefaultAddressSize(32);
            $rt2->cpuContext()->cacheSegmentDescriptor(RegisterType::CS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true, 'default' => 32]);
            $rt2->cpuContext()->cacheSegmentDescriptor(RegisterType::DS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true, 'default' => 32]);
            $rt2->cpuContext()->cacheSegmentDescriptor(RegisterType::SS, ['base' => 0, 'limit' => 0xFFFFFFFF, 'present' => true, 'default' => 32]);
            $rt2->cpuContext()->setGdtr(0x5000, 0x17);

            $rt2->memory()->setOffset(0x5000);
            $rt2->memory()->write($gdt);

            $rt2->setRegister(RegisterType::CS, 0x0008, 16);
            $rt2->setRegister(RegisterType::DS, 0x0010, 16);
            $rt2->setRegister(RegisterType::SS, 0x0010, 16);

            $rt2->memory()->setOffset(0x89ED);
            $rt2->memory()->write(implode('', array_map('chr', $decodeBitBytes)));
            $rt2->memory()->setOffset($ip);
            $rt2->memory()->write(implode('', array_map('chr', $literalBytes)));

            $rt2->memory()->setOffset($probBase);
            $rt2->memory()->write(str_repeat(pack('V', 0x00000400), intdiv($probLen, 4)));

            $rt2->writeMemory($rangeAddr, 0x20000000, 32);
            $rt2->writeMemory($codeAddr, 0x00000000, 32);

            $rt2->memory()->setOffset($esi);
            $rt2->memory()->write(str_repeat("\x00", 256));

            $rt2->writeMemory($stackAddr, $case['matchByte'], 32);
            $rt2->writeMemory($stackAddr + 4, $baseIndex, 32);

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

            $pat = $this->snapshotState($rt2, $probBase, $probLen, $stackAddr, $rangeAddr, $codeAddr);

            $this->assertSame($interp, $pat, sprintf('Mismatch for matchByte=0x%02X', $case['matchByte']));
        }
    }
}
