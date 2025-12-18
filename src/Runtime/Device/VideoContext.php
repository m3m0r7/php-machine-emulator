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

    /** @var array{base:int,width:int,height:int,bytesPerScanLine:int,bitsPerPixel:int,size:int}|null */
    private ?array $linearFramebufferInfo = null;
    private string $linearFramebufferData = '';

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

    public function enableLinearFramebuffer(
        int $baseAddress,
        int $width,
        int $height,
        int $bytesPerScanLine,
        int $bitsPerPixel,
    ): void {
        $size = $bytesPerScanLine * $height;
        if ($size <= 0) {
            $this->disableLinearFramebuffer();
            return;
        }

        $this->linearFramebufferInfo = [
            'base' => $baseAddress & 0xFFFFFFFF,
            'width' => $width,
            'height' => $height,
            'bytesPerScanLine' => $bytesPerScanLine,
            'bitsPerPixel' => $bitsPerPixel,
            'size' => $size,
        ];

        $this->linearFramebufferData = str_repeat("\0", $size);
    }

    public function disableLinearFramebuffer(): void
    {
        $this->linearFramebufferInfo = null;
        $this->linearFramebufferData = '';
    }

    public function linearFramebufferInfo(): ?array
    {
        return $this->linearFramebufferInfo;
    }

    public function linearFramebufferRead(int $address, int $width): ?int
    {
        $info = $this->linearFramebufferInfo;
        if ($info === null) {
            return null;
        }

        $offset = ($address - $info['base']) & 0xFFFFFFFF;
        if ($offset < 0 || $offset >= $info['size']) {
            return null;
        }

        $data = $this->linearFramebufferData;
        return match ($width) {
            8 => ord($data[$offset]),
            16 => ($offset + 1 < $info['size'])
                ? (ord($data[$offset]) | (ord($data[$offset + 1]) << 8))
                : null,
            32 => ($offset + 3 < $info['size'])
                ? (ord($data[$offset])
                    | (ord($data[$offset + 1]) << 8)
                    | (ord($data[$offset + 2]) << 16)
                    | (ord($data[$offset + 3]) << 24))
                : null,
            64 => ($offset + 7 < $info['size'])
                ? ((ord($data[$offset])
                    | (ord($data[$offset + 1]) << 8)
                    | (ord($data[$offset + 2]) << 16)
                    | (ord($data[$offset + 3]) << 24))
                    | ((ord($data[$offset + 4])
                        | (ord($data[$offset + 5]) << 8)
                        | (ord($data[$offset + 6]) << 16)
                        | (ord($data[$offset + 7]) << 24)) << 32))
                : null,
            default => null,
        };
    }

    public function linearFramebufferWrite(int $address, int $value, int $width): bool
    {
        $info = $this->linearFramebufferInfo;
        if ($info === null) {
            return false;
        }

        $offset = ($address - $info['base']) & 0xFFFFFFFF;
        if ($offset < 0 || $offset >= $info['size']) {
            return false;
        }

        $data = $this->linearFramebufferData;
        $ok = match ($width) {
            8 => $offset < $info['size'],
            16 => ($offset + 1) < $info['size'],
            32 => ($offset + 3) < $info['size'],
            64 => ($offset + 7) < $info['size'],
            default => false,
        };
        if (!$ok) {
            return false;
        }

        // Update backing store (little-endian).
        $data[$offset] = chr($value & 0xFF);
        if ($width >= 16) {
            $data[$offset + 1] = chr(($value >> 8) & 0xFF);
        }
        if ($width >= 32) {
            $data[$offset + 2] = chr(($value >> 16) & 0xFF);
            $data[$offset + 3] = chr(($value >> 24) & 0xFF);
        }
        if ($width >= 64) {
            $data[$offset + 4] = chr(($value >> 32) & 0xFF);
            $data[$offset + 5] = chr(($value >> 40) & 0xFF);
            $data[$offset + 6] = chr(($value >> 48) & 0xFF);
            $data[$offset + 7] = chr(($value >> 56) & 0xFF);
        }

        $this->linearFramebufferData = $data;
        return true;
    }
}
