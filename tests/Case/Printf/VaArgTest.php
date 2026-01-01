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
 * Test va_arg-style argument access in protected mode.
 *
 * This tests EBP-relative addressing for variadic arguments,
 * simulating how COM32's printf accesses stack arguments.
 *
 * Expected: Video memory at 0xB8000 contains "Test:7"
 * Markers: 0xB000 = 0xAA (start), 0xB001 = 0xFF (success)
 */
class VaArgTest extends TestCase
{
    use CreateApplication;

    private const MARKER_BASE = 0xB000;

    public static function vaArgDataProvider(): array
    {
        $bootStream = new BootableFileStream(__DIR__ . '/Fixture/VaArgTest.o', 0x7C00);
        return self::machineInitializationWithMachine($bootStream);
    }

    #[DataProvider('vaArgDataProvider')]
    #[Test]
    public function vaArgAccessWorksCorrectly(MachineInterface $machine, OptionInterface $option): void
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
        for ($i = 0; $i < 160; $i += 2) {
            $char = $memoryAccessor->readRawByte(0xB8000 + $i);
            if ($char >= 0x20 && $char < 0x7F) {
                $videoMem .= chr($char);
            }
        }
        $videoMem = trim($videoMem);

        // Should contain "Test:7"
        $this->assertStringContainsString(
            'Test:7',
            $videoMem,
            'va_arg should correctly read argument from stack. Video memory contains: ' . $videoMem
        );

        // Should NOT contain "%d"
        $this->assertStringNotContainsString(
            'Test:%d',
            $videoMem,
            'Format specifier should be processed, not output literally'
        );

        // Should NOT contain just "d"
        $this->assertStringNotContainsString(
            'Test:d',
            $videoMem,
            'Format specifier "d" should be recognized'
        );
    }
}
