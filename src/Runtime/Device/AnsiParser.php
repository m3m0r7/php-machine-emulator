<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Device;

use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * ANSI escape sequence parser implementation.
 *
 * Parses ANSI/VT100 escape sequences for cursor control, colors, etc.
 */
class AnsiParser implements AnsiParserInterface
{
    private int $state = self::STATE_NORMAL;
    private string $buffer = '';

    public function getState(): int
    {
        return $this->state;
    }

    public function reset(): void
    {
        $this->state = self::STATE_NORMAL;
        $this->buffer = '';
    }

    public function getBuffer(): string
    {
        return $this->buffer;
    }

    public function processChar(int $char, RuntimeInterface $runtime, VideoContextInterface $videoContext): bool
    {
        // ESC state handling
        if ($this->state === self::STATE_ESC) {
            if ($char === 0x5B) { // '['
                // ESC [ - CSI sequence start
                $this->state = self::STATE_CSI;
                $this->buffer = '';
                return true;
            }
            // Not a CSI sequence, reset and let character be handled normally
            $this->state = self::STATE_NORMAL;
            return false;
        }

        // CSI state handling
        if ($this->state === self::STATE_CSI) {
            // Collecting CSI parameters
            // Parameters are digits 0-9, semicolons, and terminated by a letter
            if (($char >= 0x30 && $char <= 0x3F) || $char === 0x3B) {
                // 0-9, :, ;, <, =, >, ? - parameter bytes
                $this->buffer .= chr($char);
                return true;
            }
            if ($char >= 0x40 && $char <= 0x7E) {
                // Final byte (command)
                $this->executeCommand($runtime, chr($char), $this->buffer, $videoContext);
                $this->state = self::STATE_NORMAL;
                $this->buffer = '';
                return true;
            }
            // Invalid sequence, reset
            $this->state = self::STATE_NORMAL;
            $this->buffer = '';
            return false;
        }

        // Check for ESC character
        if ($char === 0x1B) {
            $this->state = self::STATE_ESC;
            return true;
        }

        return false;
    }

    /**
     * Execute an ANSI CSI command.
     */
    protected function executeCommand(RuntimeInterface $runtime, string $command, string $params, VideoContextInterface $videoContext): void
    {
        // Parse parameters (semicolon-separated numbers)
        $args = array_map('intval', explode(';', $params));
        if (empty($args) || ($args[0] === 0 && $params === '')) {
            $args = [1]; // Default parameter
        }

        switch ($command) {
            case 'H': // CUP - Cursor Position (row;col)
            case 'f': // HVP - Horizontal and Vertical Position
                $row = ($args[0] ?? 1) - 1; // 1-based to 0-based
                $col = ($args[1] ?? 1) - 1;
                $videoContext->setCursorPosition(max(0, $row), max(0, $col));
                $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
                break;

            case 'A': // CUU - Cursor Up
                $n = $args[0] ?? 1;
                $videoContext->setCursorPosition(max(0, $videoContext->getCursorRow() - $n), $videoContext->getCursorCol());
                $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
                break;

            case 'B': // CUD - Cursor Down
                $n = $args[0] ?? 1;
                $videoContext->setCursorPosition($videoContext->getCursorRow() + $n, $videoContext->getCursorCol());
                $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
                break;

            case 'C': // CUF - Cursor Forward
                $n = $args[0] ?? 1;
                $videoContext->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol() + $n);
                $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
                break;

            case 'D': // CUB - Cursor Back
                $n = $args[0] ?? 1;
                $videoContext->setCursorPosition($videoContext->getCursorRow(), max(0, $videoContext->getCursorCol() - $n));
                $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
                break;

            case 'd': // VPA - Vertical Position Absolute (move to row)
                $row = ($args[0] ?? 1) - 1; // 1-based to 0-based
                $videoContext->setCursorPosition(max(0, $row), $videoContext->getCursorCol());
                $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
                break;

            case 'G': // CHA - Cursor Horizontal Absolute (move to column)
            case '`': // HPA - Horizontal Position Absolute
                $col = ($args[0] ?? 1) - 1; // 1-based to 0-based
                $videoContext->setCursorPosition($videoContext->getCursorRow(), max(0, $col));
                $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
                break;

            case 'J': // ED - Erase in Display
                $n = $args[0] ?? 0;
                if ($n === 2) {
                    // Clear entire screen
                    $runtime->context()->screen()->clear();
                    $videoContext->setCursorPosition(0, 0);
                }
                // 0 = clear from cursor to end, 1 = clear from start to cursor (not implemented)
                break;

            case 'K': // EL - Erase in Line
                // 0 = clear from cursor to end of line
                // 1 = clear from start of line to cursor
                // 2 = clear entire line
                // Stub for now
                break;

            case 'm': // SGR - Select Graphic Rendition (colors, attributes)
                $this->handleSgr($runtime, $args);
                break;

            case 's': // SCP - Save Cursor Position
                // Stub
                break;

            case 'u': // RCP - Restore Cursor Position
                // Stub
                break;

            default:
                // Unknown command, ignore
                break;
        }
    }

    /**
     * Handle SGR (Select Graphic Rendition) - text attributes and colors.
     */
    protected function handleSgr(RuntimeInterface $runtime, array $args): void
    {
        foreach ($args as $code) {
            switch ($code) {
                case 0: // Reset
                    $runtime->context()->screen()->setCurrentAttribute(0x07); // Default white on black
                    break;
                case 1: // Bold/bright
                    $attr = $runtime->context()->screen()->getCurrentAttribute();
                    $runtime->context()->screen()->setCurrentAttribute($attr | 0x08); // Set bright bit
                    break;
                case 7: // Reverse video
                    $attr = $runtime->context()->screen()->getCurrentAttribute();
                    $fg = $attr & 0x0F;
                    $bg = ($attr >> 4) & 0x0F;
                    $runtime->context()->screen()->setCurrentAttribute(($fg << 4) | $bg);
                    break;
                case 30: case 31: case 32: case 33: case 34: case 35: case 36: case 37:
                    // Foreground color (30-37 -> 0-7)
                    $attr = $runtime->context()->screen()->getCurrentAttribute();
                    $fg = $code - 30;
                    $runtime->context()->screen()->setCurrentAttribute(($attr & 0xF8) | $fg);
                    break;
                case 40: case 41: case 42: case 43: case 44: case 45: case 46: case 47:
                    // Background color (40-47 -> 0-7)
                    $attr = $runtime->context()->screen()->getCurrentAttribute();
                    $bg = $code - 40;
                    $runtime->context()->screen()->setCurrentAttribute(($attr & 0x0F) | ($bg << 4));
                    break;
                default:
                    // Ignore unknown SGR codes
                    break;
            }
        }
    }
}
