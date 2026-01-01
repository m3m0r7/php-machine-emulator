<?php

declare(strict_types=1);

namespace Tests\Case\Printf;

use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\MachineInterface;
use PHPMachineEmulator\OptionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\CreateApplication;
use Tests\Utils\BootableFileStream;

/**
 * Test printf-like %d formatting in protected mode.
 *
 * This tests that stack arguments are correctly read when calling
 * functions using the cdecl calling convention.
 *
 * Expected: Video memory at 0xB8000 contains "Row:42 Pos:10,5"
 * Markers: 0xB000 = 0xAA (start), 0xB001 = 0xFF (success)
 */
class PrintfTest extends TestCase
{
    use CreateApplication;

    private const MARKER_BASE = 0xB000;

    public static function printfDataProvider(): array
    {
        $bootStream = new BootableFileStream(__DIR__ . '/Fixture/Printf.o', 0x7C00);
        return self::machineInitializationWithMachine($bootStream);
    }

    #[DataProvider('printfDataProvider')]
    #[Test]
    public function printfFormatsDecimalCorrectly(MachineInterface $machine, OptionInterface $option): void
    {
        try {
            self::bootBios($machine);
        } catch (ExitException|HaltException) {
            // Expected - test halts after completion
        }

        $memoryAccessor = $machine->runtime()->memoryAccessor();

        // Check start marker
        $startMarker = $memoryAccessor->readRawByte(self::MARKER_BASE);
        $this->assertSame(
            0xAA,
            $startMarker,
            'Start marker should be 0xAA'
        );

        // Check success marker
        $successMarker = $memoryAccessor->readRawByte(self::MARKER_BASE + 1);
        $this->assertSame(
            0xFF,
            $successMarker,
            sprintf('Success marker should be 0xFF, got 0x%02X', $successMarker)
        );

        // Read video memory to verify output
        $videoMem = '';
        for ($i = 0; $i < 160; $i += 2) { // First row of video memory
            $char = $memoryAccessor->readRawByte(0xB8000 + $i);
            if ($char >= 0x20 && $char < 0x7F) {
                $videoMem .= chr($char);
            }
        }
        $videoMem = trim($videoMem);

        // Should contain "Row:42" (printf %d working correctly)
        $this->assertStringContainsString(
            'Row:42',
            $videoMem,
            'printf should format %d as decimal number. Video memory contains: ' . $videoMem
        );

        // Should NOT contain literal "%d" or just "d" (printf %d broken)
        $this->assertStringNotContainsString(
            'Row:%d',
            $videoMem,
            'printf should NOT output literal %d'
        );

        $this->assertStringNotContainsString(
            'Row:d',
            $videoMem,
            'printf should NOT output partial literal d'
        );

        // Should contain "Pos:10,5" (multiple %d working)
        $this->assertStringContainsString(
            'Pos:10,5',
            $videoMem,
            'printf should handle multiple %d. Video memory contains: ' . $videoMem
        );
    }
}
