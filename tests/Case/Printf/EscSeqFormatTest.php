<?php

declare(strict_types=1);

namespace Tests\Case\Printf;

use PHPMachineEmulator\BIOS;
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
 * Test printf with escape sequence format string.
 *
 * This simulates exactly what SYSLINUX menu does:
 * printf("\033[%d;1H", 5)
 *
 * The format string contains ESC character followed by [%d;1H
 * The %d should be replaced with the row number.
 *
 * Expected output buffer: ESC [ 5 ; 1 H (6 bytes)
 * NOT: ESC [ d ; 1 H (format failed)
 *
 * Markers: 0xB000 = 0xAA (start), 0xB001 = 0xFF (success)
 */
class EscSeqFormatTest extends TestCase
{
    use CreateApplication;

    private const MARKER_BASE = 0xB000;
    private const OUTPUT_BUFFER = 0xB100;

    public static function escSeqDataProvider(): array
    {
        $bootStream = new BootableFileStream(__DIR__ . '/Fixture/EscSeqFormat.o', 0x7C00);
        return self::machineInitializationWithMachine($bootStream);
    }

    #[DataProvider('escSeqDataProvider')]
    #[Test]
    public function escapeSequenceFormatWorksCorrectly(MachineInterface $machine, OptionInterface $option): void
    {
        try {
            BIOS::start($machine);
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

        // Read output buffer to see what was actually formatted
        $output = '';
        $outputHex = [];
        for ($i = 0; $i < 10; $i++) {
            $byte = $memoryAccessor->readRawByte(self::OUTPUT_BUFFER + $i);
            if ($byte === 0) {
                break;
            }
            $outputHex[] = sprintf('%02X', $byte);
            if ($byte >= 0x20 && $byte < 0x7F) {
                $output .= chr($byte);
            } else {
                $output .= sprintf('\\x%02X', $byte);
            }
        }

        // Check success marker
        $successMarker = $memoryAccessor->readRawByte(self::MARKER_BASE + 1);
        $this->assertSame(
            0xFF,
            $successMarker,
            sprintf(
                'Success marker should be 0xFF, got 0x%02X. Output buffer: %s (hex: %s)',
                $successMarker,
                $output,
                implode(' ', $outputHex)
            )
        );

        // Verify output is ESC [ 5 ; 1 H
        $byte0 = $memoryAccessor->readRawByte(self::OUTPUT_BUFFER + 0);
        $byte1 = $memoryAccessor->readRawByte(self::OUTPUT_BUFFER + 1);
        $byte2 = $memoryAccessor->readRawByte(self::OUTPUT_BUFFER + 2);
        $byte3 = $memoryAccessor->readRawByte(self::OUTPUT_BUFFER + 3);
        $byte4 = $memoryAccessor->readRawByte(self::OUTPUT_BUFFER + 4);
        $byte5 = $memoryAccessor->readRawByte(self::OUTPUT_BUFFER + 5);

        $this->assertSame(0x1B, $byte0, 'Byte 0 should be ESC (0x1B)');
        $this->assertSame(ord('['), $byte1, 'Byte 1 should be [');
        $this->assertSame(ord('5'), $byte2, 'Byte 2 should be 5 (formatted from %d)');
        $this->assertSame(ord(';'), $byte3, 'Byte 3 should be ;');
        $this->assertSame(ord('1'), $byte4, 'Byte 4 should be 1');
        $this->assertSame(ord('H'), $byte5, 'Byte 5 should be H');
    }
}
