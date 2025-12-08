<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use PHPMachineEmulator\Runtime\Device\AnsiParser;
use PHPMachineEmulator\Runtime\Device\AnsiParserInterface;
use PHPMachineEmulator\Runtime\Device\VideoContext;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Runtime\RuntimeContextInterface;
use PHPMachineEmulator\Runtime\RuntimeScreenContextInterface;
use PHPMachineEmulator\OptionInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AnsiParserTest extends TestCase
{
    private AnsiParser $parser;
    private VideoContext $videoContext;
    private RuntimeInterface $runtime;

    protected function setUp(): void
    {
        $this->parser = new AnsiParser();
        $this->videoContext = new VideoContext();
        $this->runtime = $this->createMockRuntime();
    }

    private function createMockRuntime(): RuntimeInterface
    {
        // Create mock screen with all required methods
        $screen = $this->createMock(RuntimeScreenContextInterface::class);
        $screen->method('setCursorPosition');
        $screen->method('clear');
        $screen->method('setCurrentAttribute');
        $screen->method('getCurrentAttribute')->willReturn(0x07);

        // Create mock context
        $context = $this->createMock(RuntimeContextInterface::class);
        $context->method('screen')->willReturn($screen);

        // Create mock logger
        $logger = $this->createMock(LoggerInterface::class);

        // Create mock option
        $option = $this->createMock(OptionInterface::class);
        $option->method('logger')->willReturn($logger);

        // Create mock runtime
        $runtime = $this->createMock(RuntimeInterface::class);
        $runtime->method('context')->willReturn($context);
        $runtime->method('option')->willReturn($option);

        return $runtime;
    }

    #[Test]
    public function initialStateIsNormal(): void
    {
        $this->assertSame(AnsiParserInterface::STATE_NORMAL, $this->parser->getState());
    }

    #[Test]
    public function escCharacterChangesToEscState(): void
    {
        $consumed = $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(AnsiParserInterface::STATE_ESC, $this->parser->getState());
    }

    #[Test]
    public function escBracketChangesToCsiState(): void
    {
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext); // ESC
        $consumed = $this->parser->processChar(0x5B, $this->runtime, $this->videoContext); // [

        $this->assertTrue($consumed);
        $this->assertSame(AnsiParserInterface::STATE_CSI, $this->parser->getState());
    }

    #[Test]
    public function normalCharacterNotConsumed(): void
    {
        $consumed = $this->parser->processChar(ord('A'), $this->runtime, $this->videoContext);

        $this->assertFalse($consumed);
        $this->assertSame(AnsiParserInterface::STATE_NORMAL, $this->parser->getState());
    }

    #[Test]
    public function csiSequenceWithHCommand(): void
    {
        // ESC [ 5 ; 1 0 H - Cursor to row 5, col 10
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext); // ESC
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext); // [
        $this->parser->processChar(ord('5'), $this->runtime, $this->videoContext);
        $this->parser->processChar(ord(';'), $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('1'), $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('0'), $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('H'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(AnsiParserInterface::STATE_NORMAL, $this->parser->getState());
        // Cursor should be at row 4 (5-1), col 9 (10-1) - 0-indexed
        $this->assertSame(4, $this->videoContext->getCursorRow());
        $this->assertSame(9, $this->videoContext->getCursorCol());
    }

    #[Test]
    public function csiDCommandVpa(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(5, 20);

        // ESC [ 1 0 d - VPA: move to row 10 (column unchanged)
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('1'), $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('0'), $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('d'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(AnsiParserInterface::STATE_NORMAL, $this->parser->getState());
        // Row should be 9 (10-1, 0-indexed), column unchanged at 20
        $this->assertSame(9, $this->videoContext->getCursorRow());
        $this->assertSame(20, $this->videoContext->getCursorCol());
    }

    #[Test]
    public function csiGCommandCha(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(5, 20);

        // ESC [ 1 5 G - CHA: move to column 15 (row unchanged)
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('1'), $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('5'), $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('G'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(AnsiParserInterface::STATE_NORMAL, $this->parser->getState());
        // Row unchanged at 5, column should be 14 (15-1, 0-indexed)
        $this->assertSame(5, $this->videoContext->getCursorRow());
        $this->assertSame(14, $this->videoContext->getCursorCol());
    }

    #[Test]
    public function csiACommandCursorUp(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(10, 5);

        // ESC [ 3 A - Cursor Up 3 lines
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('3'), $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('A'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(7, $this->videoContext->getCursorRow()); // 10 - 3 = 7
        $this->assertSame(5, $this->videoContext->getCursorCol()); // unchanged
    }

    #[Test]
    public function csiBCommandCursorDown(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(5, 10);

        // ESC [ 2 B - Cursor Down 2 lines
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('2'), $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('B'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(7, $this->videoContext->getCursorRow()); // 5 + 2 = 7
        $this->assertSame(10, $this->videoContext->getCursorCol()); // unchanged
    }

    #[Test]
    public function csiCCommandCursorForward(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(5, 10);

        // ESC [ 5 C - Cursor Forward 5 columns
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('5'), $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('C'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(5, $this->videoContext->getCursorRow()); // unchanged
        $this->assertSame(15, $this->videoContext->getCursorCol()); // 10 + 5 = 15
    }

    #[Test]
    public function csiDCommandCursorBack(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(5, 10);

        // ESC [ 4 D - Cursor Back 4 columns
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('4'), $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('D'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(5, $this->videoContext->getCursorRow()); // unchanged
        $this->assertSame(6, $this->videoContext->getCursorCol()); // 10 - 4 = 6
    }

    #[Test]
    public function resetClearsState(): void
    {
        // Put parser in CSI state
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('1'), $this->runtime, $this->videoContext);

        $this->assertSame(AnsiParserInterface::STATE_CSI, $this->parser->getState());
        $this->assertSame('1', $this->parser->getBuffer());

        $this->parser->reset();

        $this->assertSame(AnsiParserInterface::STATE_NORMAL, $this->parser->getState());
        $this->assertSame('', $this->parser->getBuffer());
    }

    #[Test]
    public function fullSequenceIsConsumed(): void
    {
        // Test that \e[d is consumed as VPA command
        // Note: In standard ANSI, \e[d;1H is actually TWO sequences:
        // 1. \e[d - VPA (vertical position absolute, default row 1)
        // 2. ;1H - These characters are NOT part of the escape sequence
        //
        // The 'd' character (0x64) is in the final byte range (0x40-0x7E),
        // so it terminates the CSI sequence immediately.
        //
        // If SYSLINUX outputs "\e[d;1H" expecting it to be a single CUP command,
        // that's non-standard behavior. The proper CSI for row d, col 1 would be
        // something like \e[;1H (row default, col 1) or \e[1;1H (row 1, col 1).
        $sequence = "\x1B[d";
        $allConsumed = true;

        foreach (str_split($sequence) as $char) {
            $consumed = $this->parser->processChar(ord($char), $this->runtime, $this->videoContext);
            if (!$consumed) {
                $allConsumed = false;
                break;
            }
        }

        $this->assertTrue($allConsumed, 'All characters in escape sequence should be consumed');
        $this->assertSame(AnsiParserInterface::STATE_NORMAL, $this->parser->getState());
    }

    #[Test]
    #[DataProvider('escapeSequenceProvider')]
    public function escapeSequencesAreFullyConsumed(string $sequence, string $description): void
    {
        foreach (str_split($sequence) as $char) {
            $consumed = $this->parser->processChar(ord($char), $this->runtime, $this->videoContext);
            $this->assertTrue($consumed, "Character '" . $char . "' should be consumed in sequence: {$description}");
        }

        $this->assertSame(AnsiParserInterface::STATE_NORMAL, $this->parser->getState(), "Parser should return to normal state after: {$description}");
    }

    public static function escapeSequenceProvider(): array
    {
        return [
            ["\x1B[H", 'Cursor Home'],
            ["\x1B[1;1H", 'Cursor Position 1,1'],
            ["\x1B[10;20H", 'Cursor Position 10,20'],
            ["\x1B[d", 'VPA default'],
            ["\x1B[1d", 'VPA row 1'],
            ["\x1B[25d", 'VPA row 25'],
            ["\x1B[G", 'CHA default'],
            ["\x1B[1G", 'CHA column 1'],
            ["\x1B[80G", 'CHA column 80'],
            ["\x1B[A", 'Cursor Up default'],
            ["\x1B[5A", 'Cursor Up 5'],
            ["\x1B[B", 'Cursor Down default'],
            ["\x1B[3B", 'Cursor Down 3'],
            ["\x1B[C", 'Cursor Forward default'],
            ["\x1B[10C", 'Cursor Forward 10'],
            ["\x1B[D", 'Cursor Back default'],
            ["\x1B[2D", 'Cursor Back 2'],
            ["\x1B[2J", 'Clear Screen'],
            ["\x1B[0m", 'Reset attributes'],
            ["\x1B[1m", 'Bold'],
            ["\x1B[31m", 'Foreground red'],
            ["\x1B[44m", 'Background blue'],
            ["\x1B[1;31;44m", 'Bold red on blue'],
        ];
    }

    #[Test]
    public function nonEscapeCharactersNotConsumedAfterSequence(): void
    {
        // Process a complete sequence
        foreach (str_split("\x1B[H") as $char) {
            $this->parser->processChar(ord($char), $this->runtime, $this->videoContext);
        }

        // Now a normal character should NOT be consumed
        $consumed = $this->parser->processChar(ord('A'), $this->runtime, $this->videoContext);
        $this->assertFalse($consumed, 'Normal character after sequence should not be consumed');
    }

    #[Test]
    public function cursorHomeDefaultsToZeroZero(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(10, 20);

        // ESC [ H - Cursor Home (no parameters = 1,1 which is 0,0 in 0-indexed)
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('H'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(0, $this->videoContext->getCursorRow());
        $this->assertSame(0, $this->videoContext->getCursorCol());
    }

    #[Test]
    public function cursorUpDefaultsToOne(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(10, 5);

        // ESC [ A - Cursor Up (no parameter = 1)
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('A'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(9, $this->videoContext->getCursorRow()); // 10 - 1 = 9
    }

    #[Test]
    public function cursorDownDefaultsToOne(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(5, 5);

        // ESC [ B - Cursor Down (no parameter = 1)
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('B'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(6, $this->videoContext->getCursorRow()); // 5 + 1 = 6
    }

    #[Test]
    public function cursorForwardDefaultsToOne(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(5, 10);

        // ESC [ C - Cursor Forward (no parameter = 1)
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('C'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(11, $this->videoContext->getCursorCol()); // 10 + 1 = 11
    }

    #[Test]
    public function cursorBackDefaultsToOne(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(5, 10);

        // ESC [ D - Cursor Back (no parameter = 1)
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('D'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(9, $this->videoContext->getCursorCol()); // 10 - 1 = 9
    }

    #[Test]
    public function cursorUpDoesNotGoBelowZero(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(2, 5);

        // ESC [ 1 0 A - Cursor Up 10 (but only 2 rows from top)
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('1'), $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('0'), $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('A'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(0, $this->videoContext->getCursorRow()); // max(0, 2-10) = 0
    }

    #[Test]
    public function cursorBackDoesNotGoBelowZero(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(5, 3);

        // ESC [ 1 0 D - Cursor Back 10 (but only 3 columns from left)
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('1'), $this->runtime, $this->videoContext);
        $this->parser->processChar(ord('0'), $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('D'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(0, $this->videoContext->getCursorCol()); // max(0, 3-10) = 0
    }

    #[Test]
    public function vpaDefaultsToRowOne(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(10, 20);

        // ESC [ d - VPA (no parameter = row 1, which is 0 in 0-indexed)
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('d'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(0, $this->videoContext->getCursorRow());
        $this->assertSame(20, $this->videoContext->getCursorCol()); // unchanged
    }

    #[Test]
    public function chaDefaultsToColumnOne(): void
    {
        // Set initial cursor position
        $this->videoContext->setCursorPosition(10, 20);

        // ESC [ G - CHA (no parameter = column 1, which is 0 in 0-indexed)
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->parser->processChar(0x5B, $this->runtime, $this->videoContext);
        $consumed = $this->parser->processChar(ord('G'), $this->runtime, $this->videoContext);

        $this->assertTrue($consumed);
        $this->assertSame(10, $this->videoContext->getCursorRow()); // unchanged
        $this->assertSame(0, $this->videoContext->getCursorCol());
    }

    #[Test]
    public function invalidEscapeSequenceResetsState(): void
    {
        // ESC followed by invalid character (not '[')
        $this->parser->processChar(0x1B, $this->runtime, $this->videoContext);
        $this->assertSame(AnsiParserInterface::STATE_ESC, $this->parser->getState());

        // 'X' is not a valid CSI introducer
        $consumed = $this->parser->processChar(ord('X'), $this->runtime, $this->videoContext);

        // After invalid sequence, should return to normal state
        $this->assertSame(AnsiParserInterface::STATE_NORMAL, $this->parser->getState());
    }

    #[Test]
    public function syslinuxStyleSequenceD1H(): void
    {
        // This is the problematic sequence from SYSLINUX: ESC [ d ; 1 H
        // In standard ANSI:
        // - \e[ starts CSI
        // - 'd' (0x64) is a final byte, so \e[d is VPA (row 1, default)
        // - ;1H are NOT consumed (they appear as garbage on screen)
        //
        // This explains the "d;1H" garbage appearing in SYSLINUX menu!
        // SYSLINUX may be outputting non-standard sequences or there's
        // something wrong with how the emulator processes output.

        $this->videoContext->setCursorPosition(10, 20);

        $sequence = "\x1B[d;1H";
        $consumedChars = [];

        foreach (str_split($sequence) as $i => $char) {
            $consumed = $this->parser->processChar(ord($char), $this->runtime, $this->videoContext);
            $consumedChars[$i] = ['char' => $char, 'ord' => ord($char), 'consumed' => $consumed];
        }

        // After \e[d, the parser returns to normal state
        // So ';', '1', 'H' are NOT consumed
        $this->assertFalse($consumedChars[3]['consumed'], '; should NOT be consumed (not part of escape seq)');
        $this->assertFalse($consumedChars[4]['consumed'], '1 should NOT be consumed');
        $this->assertFalse($consumedChars[5]['consumed'], 'H should NOT be consumed');

        // But wait - if SYSLINUX outputs this and expects it to work,
        // maybe it's outputting \e[;1H (with empty first parameter)?
        // Let's verify that sequence works:
        $this->parser->reset();
        $this->videoContext->setCursorPosition(10, 20);

        $correctSequence = "\x1B[;1H"; // Empty first param, col=1
        foreach (str_split($correctSequence) as $char) {
            $consumed = $this->parser->processChar(ord($char), $this->runtime, $this->videoContext);
            $this->assertTrue($consumed, "Char '{$char}' should be consumed in \\e[;1H");
        }
        $this->assertSame(AnsiParserInterface::STATE_NORMAL, $this->parser->getState());
    }
}
