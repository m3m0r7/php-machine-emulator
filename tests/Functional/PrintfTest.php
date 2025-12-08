<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPMachineEmulator\Machine;
use PHPMachineEmulator\Option;
use PHPMachineEmulator\Display\Writer\BufferScreenWriter;
use PHPMachineEmulator\Stream\File;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test printf-like %d formatting in protected mode.
 *
 * This tests that stack arguments are correctly read when calling
 * functions using the cdecl calling convention.
 */
class PrintfTest extends TestCase
{
    #[Test]
    public function printfFormatsDecimalCorrectly(): void
    {
        $isoPath = __DIR__ . '/../Fixtures/ISO/PrintfTest.iso';

        if (!file_exists($isoPath)) {
            $this->markTestSkipped('PrintfTest.iso not found. Run scripts/create_printf_test_iso.sh first.');
        }

        $option = new Option(
            bootStreamOrFile: new File($isoPath),
            screenWriterFactory: new class implements \PHPMachineEmulator\Display\Writer\ScreenWriterFactoryInterface {
                public function create(\PHPMachineEmulator\Runtime\RuntimeInterface $runtime, \PHPMachineEmulator\Video\VideoTypeInfo $videoTypeInfo): \PHPMachineEmulator\Display\Writer\ScreenWriterInterface {
                    return new BufferScreenWriter($videoTypeInfo);
                }
            },
            maxInstructions: 500000,
        );

        $machine = new Machine($option);
        $machine->run();

        // Get screen content
        $screenWriter = $machine->runtime()->context()->screen()->screenWriter();
        assert($screenWriter instanceof BufferScreenWriter);

        $output = $screenWriter->getBuffer();

        // Expected: "Row:42 Pos:10,5"
        // The test implements a minimal printf that reads stack arguments

        $this->assertStringContainsString('Row:42', $output, 'printf should format %d as decimal number');
        $this->assertStringNotContainsString('Row:%d', $output, 'printf should NOT output literal %d');
        $this->assertStringNotContainsString('Row:d', $output, 'printf should NOT output partial literal d');
    }
}
