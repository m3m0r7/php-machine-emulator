<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Device;

/**
 * Video device context implementation.
 * Holds video state including cursor position, current mode, and ANSI parser state.
 */
class VideoContext implements VideoContextInterface
{
    public const NAME = 'video';

    private int $currentMode = 0x03;
    private int $cursorRow = 0;
    private int $cursorCol = 0;
    private AnsiParserInterface $ansiParser;

    public function __construct()
    {
        $this->ansiParser = new AnsiParser();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function getCurrentMode(): int
    {
        return $this->currentMode;
    }

    public function setCurrentMode(int $mode): void
    {
        $this->currentMode = $mode;
    }

    public function getCursorRow(): int
    {
        return $this->cursorRow;
    }

    public function getCursorCol(): int
    {
        return $this->cursorCol;
    }

    public function setCursorPosition(int $row, int $col): void
    {
        $this->cursorRow = $row;
        $this->cursorCol = $col;
    }

    public function ansiParser(): AnsiParserInterface
    {
        return $this->ansiParser;
    }
}
