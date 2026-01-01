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
 * Test string format parsing in protected mode.
 *
 * This tests that pointer-based string iteration works correctly
 * when implementing printf-like format string parsing.
 *
 * Expected: Video memory at 0xB8000 contains "Row:5"
 * Markers: 0xB000 = 0xAA (start), 0xB001 = 0xFF (success)
 */
class StringFormatTest extends TestCase
{
    use CreateApplication;

    private const MARKER_BASE = 0xB000;

    public static function stringFormatDataProvider(): array
    {
        $bootStream = new BootableFileStream(__DIR__ . '/Fixture/StringFormat.o', 0x7C00);
        return self::machineInitializationWithMachine($bootStream);
    }

    #[DataProvider('stringFormatDataProvider')]
    #[Test]
    public function stringFormatParsesCorrectly(MachineInterface $machine, OptionInterface $option): void
    {
        try {
            self::bootBios($machine);
        } catch (ExitException | HaltException) {
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

        // Should contain "Row:5" (printf %d working correctly)
        $this->assertStringContainsString(
            'Row:5',
            $videoMem,
            'Format string should produce "Row:5". Video memory contains: ' . $videoMem
        );

        // Should NOT contain literal "%d"
        $this->assertStringNotContainsString(
            'Row:%d',
            $videoMem,
            'Format string should NOT output literal %d'
        );

        // Should NOT contain just "d" (format specifier not recognized)
        $this->assertStringNotContainsString(
            'Row:d',
            $videoMem,
            'Format string should NOT output literal d after %'
        );
    }
}
