<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Display\Pixel\ColorInterface;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\LogicBoard\Debug\ScreenDebugConfig;
use PHPMachineEmulator\LogicBoard\Debug\ScreenDumpCodeConfig;
use PHPMachineEmulator\LogicBoard\Debug\ScreenDumpMemoryConfig;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

class TerminalScreenWriter implements ScreenWriterInterface
{
    protected int $cursorRow = 0;
    protected int $cursorCol = 0;
    protected int $currentAttribute = 0x07; // Default: white on black
    protected int $terminalCols = 80;
    protected int $terminalRows = 24;
    protected int $graphScaleX = 1;
    protected int $graphScaleY = 1;

    /** @var string Buffered output for batch writing */
    protected string $outputBuffer = '';

    /** @var array<int,string> */
    private array $screenLines = [];
    private ?string $stopOnScreenSubstr = null;
    private bool $stopOnScreenTriggered = false;
    private int $stopOnScreenTail = 0;
    private int $stopOnScreenTailRemaining = 0;
    private ?int $stopOnScreenMatchedRow = null;
    private ?ScreenDumpMemoryConfig $dumpMemOnStop = null;
    private bool $dumpScreenAllOnStop = false;
    private ?ScreenDumpCodeConfig $dumpCodeOnStop = null;
    private ?int $dumpStackOnStop = null;
    private bool $dumpPtrStringsOnStop = false;
    private bool $silentOutput = false;
    private bool $supportsTrueColor = false;
    private TerminalScreenWriterOption $option;
    /** @var array<int,int> */
    private array $vgaToAnsi256;

    public function __construct(
        protected RuntimeInterface $runtime,
        protected VideoTypeInfo $videoTypeInfo,
        ?TerminalScreenWriterOption $option = null,
    ) {
        $this->option = $option ?? new TerminalScreenWriterOption();
        $this->silentOutput = $this->option->silentOutput;
        $this->supportsTrueColor = $this->option->supportsTrueColor;
        $this->vgaToAnsi256 = [
            0 => 0,    // Black
            1 => 4,    // Blue
            2 => 2,    // Green
            3 => 6,    // Cyan
            4 => 1,    // Red
            5 => 5,    // Magenta
            6 => 3,    // Brown/Yellow
            7 => 7,    // Light Gray
            8 => 8,    // Dark Gray
            9 => 12,   // Light Blue
            10 => 10,  // Light Green
            11 => 14,  // Light Cyan
            12 => 9,   // Light Red
            13 => 13,  // Light Magenta
            14 => 11,  // Yellow
            15 => 15,  // White
        ];
        $this->resolveTerminalDimensions();
        $this->updateGraphicScaling($this->videoTypeInfo);

        $this->applyScreenDebugConfig($runtime->logicBoard()->debug()->screen());
    }

    private function resolveTerminalDimensions(): void
    {
        $cols = $this->option->terminalCols;
        $rows = $this->option->terminalRows;

        $this->terminalCols = $cols > 0 ? $cols : 80;
        $this->terminalRows = $rows > 0 ? $rows : 24;
    }

    private function updateGraphicScaling(VideoTypeInfo $videoTypeInfo): void
    {
        if ($videoTypeInfo->isTextMode) {
            $this->graphScaleX = 1;
            $this->graphScaleY = 1;
            return;
        }

        $width = $videoTypeInfo->width;
        $height = $videoTypeInfo->height;

        $scaleX = (int) ceil($width / max(1, $this->terminalCols));
        $scaleY = (int) ceil($height / max(1, $this->terminalRows));

        $this->graphScaleX = max(1, $scaleX);
        $this->graphScaleY = max(1, $scaleY);
    }

    private function writeOutput(string $value): void
    {
        if ($this->silentOutput) {
            return;
        }
        $this->runtime
            ->option()
            ->IO()
            ->output()
            ->write($value);
    }

    private function applyScreenDebugConfig(ScreenDebugConfig $config): void
    {
        $this->stopOnScreenSubstr = $config->stopOnScreenSubstr;
        $this->stopOnScreenTail = max(0, $config->stopOnScreenTail);
        $this->dumpMemOnStop = $config->dumpMemory;
        $this->dumpScreenAllOnStop = $config->dumpScreenAll;
        $this->dumpCodeOnStop = $config->dumpCode;
        $this->dumpStackOnStop = $config->dumpStackLength;
        $this->dumpPtrStringsOnStop = $config->dumpPointerStrings;
    }

    private function readRawBytes(int $address, int $length): string
    {
        $ma = $this->runtime->memoryAccessor();
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $b = $ma->readRawByte(($address + $i) & 0xFFFFFFFF);
            $bytes .= chr($b ?? 0);
        }
        return $bytes;
    }

    private function dumpMemoryPreview(int $address, int $length): void
    {
        $bytes = $this->readRawBytes($address, $length);

        $ascii = preg_replace('/[^\\x20-\\x7E]/', '.', substr($bytes, 0, min(256, strlen($bytes)))) ?? '';
        $sha1 = sha1($bytes);
        $savedPath = null;
        $saveDump = $this->dumpMemOnStop?->save ?? false;
        if ($saveDump) {
            $savedPath = sprintf('debug/memdump_%08X_%d.bin', $address & 0xFFFFFFFF, $length);
            @file_put_contents($savedPath, $bytes);
        }
        $this->runtime->option()->logger()->warning(sprintf(
            'MEM: addr=0x%08X len=%d sha1=%s saved=%s ascii="%s"',
            $address & 0xFFFFFFFF,
            $length,
            $sha1,
            $savedPath ?? 'n/a',
            $ascii,
        ));
    }

    private function dumpMemoryToFile(string $label, int $address, int $length, string $pathTemplate): void
    {
        $bytes = $this->readRawBytes($address, $length);
        $sha1 = sha1($bytes);
        $savedPath = sprintf($pathTemplate, $address & 0xFFFFFFFF, $length);
        @file_put_contents($savedPath, $bytes);

        $ascii = preg_replace('/[^\\x20-\\x7E]/', '.', substr($bytes, 0, min(64, strlen($bytes)))) ?? '';
        $this->runtime->option()->logger()->warning(sprintf(
            '%s: addr=0x%08X len=%d sha1=%s saved=%s ascii="%s"',
            $label,
            $address & 0xFFFFFFFF,
            $length,
            $sha1,
            $savedPath,
            $ascii,
        ));
    }

    private function segmentDescriptorBaseFromSelector(int $selector): ?int
    {
        $cpu = $this->runtime->context()->cpu();
        $ma = $this->runtime->memoryAccessor();
        $ti = ($selector >> 2) & 0x1;
        if ($ti === 1) {
            $ldtr = $cpu->ldtr();
            $tableBase = (int) ($ldtr['base'] ?? 0);
            $tableLimit = (int) ($ldtr['limit'] ?? 0);
            if (((int) ($ldtr['selector'] ?? 0)) === 0) {
                return null;
            }
        } else {
            $gdtr = $cpu->gdtr();
            $tableBase = (int) ($gdtr['base'] ?? 0);
            $tableLimit = (int) ($gdtr['limit'] ?? 0);
        }

        $index = ($selector >> 3) & 0x1FFF;
        $offset = $tableBase + ($index * 8);
        if ($offset + 7 > $tableBase + $tableLimit) {
            return null;
        }

        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= chr($ma->readRawByte(($offset + $i) & 0xFFFFFFFF) ?? 0);
        }
        $bytes = array_values(unpack('C*', $raw) ?: []);
        if (count($bytes) !== 8) {
            return null;
        }

        $limitLow = $bytes[0] | ($bytes[1] << 8);
        $baseLow = $bytes[2] | ($bytes[3] << 8);
        $baseMid = $bytes[4];
        $access = $bytes[5];
        $gran = $bytes[6];
        $baseHigh = $bytes[7];

        $present = ($access & 0x80) !== 0;
        if (!$present) {
            return null;
        }

        $base = ($baseLow | ($baseMid << 16) | ($baseHigh << 24)) & 0xFFFFFFFF;
        return $base;
    }

    private function stackLinearAddress(int $ss, int $esp): int
    {
        if (!$this->runtime->context()->cpu()->isProtectedMode()) {
            return ((($ss & 0xFFFF) << 4) + ($esp & 0xFFFF)) & 0xFFFFFFFF;
        }

        $base = $this->segmentDescriptorBaseFromSelector($ss & 0xFFFF) ?? 0;
        return ($base + ($esp & 0xFFFFFFFF)) & 0xFFFFFFFF;
    }

    private function extractAsciiStringAt(int $address, int $maxLen): ?string
    {
        $bytes = $this->readRawBytes($address, $maxLen);
        $len = strlen($bytes);

        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $b = ord($bytes[$i]);
            if ($b === 0x00) {
                break;
            }
            if ($b < 0x20 || $b > 0x7E) {
                return null;
            }
            $out .= $bytes[$i];
        }

        if ($out === '' || strlen($out) < 6) {
            return null;
        }
        return $out;
    }

    private function dumpPointerStringsOnStop(array $regs, int $stackLinear): void
    {
        foreach ($regs as $name => $value) {
            if (!is_int($value)) {
                continue;
            }
            $addr = $value & 0xFFFFFFFF;
            $str = $this->extractAsciiStringAt($addr, 128);
            if ($str === null) {
                continue;
            }
            $this->runtime->option()->logger()->warning(sprintf(
                'PTR: %s=0x%08X "%s"',
                $name,
                $addr,
                $str,
            ));
        }

        // Also check a few potential pointers from the stack.
        $stackBytes = $this->readRawBytes($stackLinear, 64);
        $words = array_values(unpack('V*', $stackBytes) ?: []);
        $max = min(16, count($words));
        for ($i = 0; $i < $max; $i++) {
            $ptr = $words[$i] & 0xFFFFFFFF;
            $str = $this->extractAsciiStringAt($ptr, 128);
            if ($str === null) {
                continue;
            }
            $this->runtime->option()->logger()->warning(sprintf(
                'PTR: [SP+0x%02X]=0x%08X "%s"',
                $i * 4,
                $ptr,
                $str,
            ));
        }
    }

    private function updateScreenChar(int $row, int $col, string $char): void
    {
        if ($this->stopOnScreenSubstr === null) {
            return;
        }

        $line = $this->screenLines[$row] ?? '';
        if (strlen($line) <= $col) {
            $line = str_pad($line, $col + 1, ' ');
        }
        $line[$col] = $char;
        $this->screenLines[$row] = $line;

        $this->maybeStopOnScreenSubstr($row);
    }

    private function rowString(int $row): string
    {
        return $this->screenLines[$row] ?? '';
    }

    private function maybeStopOnScreenSubstr(int $row): void
    {
        if ($this->stopOnScreenSubstr === null) {
            return;
        }

        if ($this->stopOnScreenTriggered) {
            if ($this->stopOnScreenTailRemaining > 0) {
                $this->stopOnScreenTailRemaining--;
                if ($this->stopOnScreenTailRemaining <= 0) {
                    $this->haltOnScreenSubstr($this->stopOnScreenMatchedRow ?? $row);
                }
            }
            return;
        }

        $line = $this->rowString($row);
        if ($line === '' || !str_contains($line, $this->stopOnScreenSubstr)) {
            return;
        }

        $this->stopOnScreenTriggered = true;
        $this->stopOnScreenMatchedRow = $row;
        $this->stopOnScreenTailRemaining = $this->stopOnScreenTail;

        if ($this->stopOnScreenTailRemaining > 0) {
            return;
        }

        $this->haltOnScreenSubstr($row);
    }

    private function haltOnScreenSubstr(int $row): void
    {
        $line = $this->rowString($row);

        $lines = [];
        if ($this->dumpScreenAllOnStop) {
            $maxRow = 24;
            if ($this->screenLines !== []) {
                $maxRow = max($maxRow, max(array_keys($this->screenLines)));
            }
            $minRow = max(0, $maxRow - 30);
            for ($r = $minRow; $r <= $maxRow; $r++) {
                $text = trim($this->rowString($r));
                if ($text !== '') {
                    $lines[$r] = $text;
                }
            }
        } else {
            for ($r = max(0, $row - 1); $r <= $row + 1; $r++) {
                $lines[$r] = trim($this->rowString($r));
            }
        }

        $this->runtime->option()->logger()->warning(sprintf(
            'SCREEN: matched substring "%s" at row=%d line="%s"',
            $this->stopOnScreenSubstr,
            $row,
            trim($line),
        ));

        foreach ($lines as $r => $text) {
            if ($text === '') {
                continue;
            }
            $this->runtime->option()->logger()->warning(sprintf(
                'SCREEN: row=%d "%s"',
                $r,
                $text,
            ));

            if (preg_match('/free magic is broken at\\s+(0x[0-9a-fA-F]+)\\s*:\\s*(0x[0-9a-fA-F]+)/', $text, $m) === 1) {
                $this->runtime->option()->logger()->warning(sprintf(
                    'SCREEN: grub free magic broken at %s value=%s',
                    $m[1],
                    $m[2],
                ));
            }
        }

        $ma = $this->runtime->memoryAccessor();
        $linearIp = $this->runtime->memory()->offset() & 0xFFFFFFFF;
        $cs = $ma->fetch(RegisterType::CS)->asByte() & 0xFFFF;
        $ds = $ma->fetch(RegisterType::DS)->asByte() & 0xFFFF;
        $es = $ma->fetch(RegisterType::ES)->asByte() & 0xFFFF;
        $ss = $ma->fetch(RegisterType::SS)->asByte() & 0xFFFF;
        $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize(32) & 0xFFFFFFFF;
        $eax = $ma->fetch(RegisterType::EAX)->asBytesBySize(32) & 0xFFFFFFFF;
        $ebx = $ma->fetch(RegisterType::EBX)->asBytesBySize(32) & 0xFFFFFFFF;
        $ecx = $ma->fetch(RegisterType::ECX)->asBytesBySize(32) & 0xFFFFFFFF;
        $edx = $ma->fetch(RegisterType::EDX)->asBytesBySize(32) & 0xFFFFFFFF;
        $esi = $ma->fetch(RegisterType::ESI)->asBytesBySize(32) & 0xFFFFFFFF;
        $edi = $ma->fetch(RegisterType::EDI)->asBytesBySize(32) & 0xFFFFFFFF;
        $ebp = $ma->fetch(RegisterType::EBP)->asBytesBySize(32) & 0xFFFFFFFF;
        $pm = $this->runtime->context()->cpu()->isProtectedMode() ? 1 : 0;
        $pg = $this->runtime->context()->cpu()->isPagingEnabled() ? 1 : 0;
        $disk = $this->runtime->addressMap()->getDiskByAddress($linearIp);
        $diskOffset = $disk?->offset();
        $this->runtime->option()->logger()->warning(sprintf(
            'CPU: linearIP=0x%08X CS=0x%04X DS=0x%04X ES=0x%04X SS=0x%04X SP=0x%08X EAX=0x%08X EBX=0x%08X ECX=0x%08X EDX=0x%08X ESI=0x%08X EDI=0x%08X EBP=0x%08X PM=%d PG=%d diskOff=%s',
            $linearIp,
            $cs,
            $ds,
            $es,
            $ss,
            $sp,
            $eax,
            $ebx,
            $ecx,
            $edx,
            $esi,
            $edi,
            $ebp,
            $pm,
            $pg,
            $diskOffset === null ? 'n/a' : sprintf('0x%X', $diskOffset),
        ));

        if ($this->dumpCodeOnStop !== null) {
            $before = $this->dumpCodeOnStop->before;
            $start = max(0, (($linearIp & 0xFFFFFFFF) - $before));
            $this->dumpMemoryToFile(
                'CODE',
                $start,
                $this->dumpCodeOnStop->length,
                'debug/codedump_%08X_%d.bin',
            );
        }

        $stackLinear = $this->stackLinearAddress($ss, $sp);
        if ($this->dumpStackOnStop !== null) {
            $this->dumpMemoryToFile(
                'STACK',
                $stackLinear,
                $this->dumpStackOnStop,
                'debug/stackdump_%08X_%d.bin',
            );
        }

        if ($this->dumpPtrStringsOnStop) {
            $this->dumpPointerStringsOnStop([
                'EAX' => $eax,
                'EBX' => $ebx,
                'ECX' => $ecx,
                'EDX' => $edx,
                'ESI' => $esi,
                'EDI' => $edi,
                'EBP' => $ebp,
            ], $stackLinear);
        }

        if ($this->dumpMemOnStop !== null) {
            $this->dumpMemoryPreview($this->dumpMemOnStop->address, $this->dumpMemOnStop->length);
        }

        throw new HaltException('Stopped by PHPME_STOP_ON_SCREEN_SUBSTR');
    }

    public function write(string $value): void
    {
        $this->writeOutput($value);

        if ($this->stopOnScreenSubstr === null) {
            return;
        }

        // Ignore ANSI escape sequences (used by internal cursor moves/color changes).
        if (str_contains($value, "\033")) {
            return;
        }

        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];
            if ($char === "\r") {
                $this->cursorCol = 0;
                continue;
            }
            if ($char === "\n") {
                $this->cursorRow++;
                $this->cursorCol = 0;
                continue;
            }

            $code = ord($char);
            if ($code < 0x20 || $code === 0x7F) {
                continue;
            }

            $this->updateScreenChar($this->cursorRow, $this->cursorCol, $char);
            $this->cursorCol++;
        }
    }

    public function dot(int $x, int $y, ColorInterface $color): void
    {
        if (!$this->videoTypeInfo->isTextMode) {
            if (($x % $this->graphScaleX) !== 0 || ($y % $this->graphScaleY) !== 0) {
                return;
            }
            $x = intdiv($x, $this->graphScaleX);
            $y = intdiv($y, $this->graphScaleY);
            if ($x < 0 || $y < 0 || $x >= $this->terminalCols || $y >= $this->terminalRows) {
                return;
            }
        }

        // Buffer the cursor move and dot sequence instead of immediate write
        // ANSI escape sequence: move cursor then draw colored space
        if ($this->supportsTrueColor) {
            $this->outputBuffer .= sprintf(
                "\033[%d;%dH\033[38;2;%d;%d;%d;48;2;%d;%d;%d;1m \033[0m",
                $y + 1,
                $x + 1,
                $color->red(),
                $color->green(),
                $color->blue(),
                $color->red(),
                $color->green(),
                $color->blue(),
            );
            return;
        }

        $ansi = $this->rgbToAnsi256($color);
        $this->outputBuffer .= sprintf(
            "\033[%d;%dH\033[38;5;%d;48;5;%d;1m \033[0m",
            $y + 1,
            $x + 1,
            $ansi,
            $ansi,
        );
    }

    public function newline(): void
    {
        $this->writeOutput("\n");
    }

    public function setCursorPosition(int $row, int $col): void
    {
        $this->cursorRow = $row;
        $this->cursorCol = $col;
        // ANSI escape sequence to move cursor: ESC[row;colH
        $this->writeOutput(sprintf("\033[%d;%dH", $row + 1, $col + 1));
    }

    public function getCursorPosition(): array
    {
        return ['row' => $this->cursorRow, 'col' => $this->cursorCol];
    }

    public function writeCharAtCursor(string $char, int $count = 1, ?int $attribute = null): void
    {
        if ($attribute !== null) {
            // Convert VGA attribute to ANSI colors
            $fg = $attribute & 0x0F;
            $bg = ($attribute >> 4) & 0x0F;
            $this->writeOutput($this->vgaToAnsi($fg, $bg));
        }
        $this->writeOutput(str_repeat($char, $count));
        if ($attribute !== null) {
            $this->writeOutput("\033[0m"); // Reset colors
        }

        // Track screen contents for debug stop-on-substring feature.
        for ($i = 0; $i < $count; $i++) {
            $this->updateScreenChar($this->cursorRow, $this->cursorCol, $char);
            $this->cursorCol++;
        }
    }

    public function writeCharAt(int $row, int $col, string $char, ?int $attribute = null): void
    {
        // Move cursor to position and write character
        $this->writeOutput(sprintf("\033[%d;%dH", $row + 1, $col + 1));
        if ($attribute !== null) {
            $fg = $attribute & 0x0F;
            $bg = ($attribute >> 4) & 0x0F;
            $this->writeOutput($this->vgaToAnsi($fg, $bg));
        }
        $this->writeOutput($char);
        if ($attribute !== null) {
            $this->writeOutput("\033[0m");
        }
        // Update internal cursor position
        $this->cursorRow = $row;
        $this->cursorCol = $col + 1;

        $this->updateScreenChar($row, $col, $char);
    }

    public function clear(): void
    {
        // ANSI escape sequence to clear screen and move cursor to top-left
        $this->writeOutput("\033[2J\033[H");
        $this->cursorRow = 0;
        $this->cursorCol = 0;
    }

    public function fillArea(int $row, int $col, int $width, int $height, int $attribute): void
    {
        $fg = $attribute & 0x0F;
        $bg = ($attribute >> 4) & 0x0F;
        $colorCode = $this->vgaToAnsi($fg, $bg);

        for ($r = $row; $r < $row + $height; $r++) {
            $this->setCursorPosition($r, $col);
            $this->writeOutput($colorCode . str_repeat(' ', $width) . "\033[0m");
        }
    }

    public function scrollUpWindow(
        int $topRow,
        int $leftCol,
        int $bottomRow,
        int $rightCol,
        int $lines,
        int $attribute
    ): void
    {
        $width = $rightCol - $leftCol + 1;
        $height = $bottomRow - $topRow + 1;
        if ($width <= 0 || $height <= 0) {
            return;
        }

        $origRow = $this->cursorRow;
        $origCol = $this->cursorCol;

        if ($lines <= 0 || $lines >= $height) {
            $this->fillArea($topRow, $leftCol, $width, $height, $attribute);
            $this->setCursorPosition($origRow, $origCol);
            return;
        }

        if ($this->stopOnScreenSubstr !== null) {
            for ($row = $topRow; $row <= $bottomRow - $lines; $row++) {
                $this->screenLines[$row] = $this->screenLines[$row + $lines] ?? '';
            }
            $blank = str_repeat(' ', $width);
            for ($row = $bottomRow - $lines + 1; $row <= $bottomRow; $row++) {
                $this->screenLines[$row] = $blank;
            }
        }

        $fullWidth = $leftCol === 0 && $rightCol >= ($this->terminalCols - 1);
        if ($fullWidth) {
            $this->writeOutput(sprintf("\033[%d;%dr", $topRow + 1, $bottomRow + 1));
            $this->writeOutput(sprintf("\033[%dS", $lines));
            $this->writeOutput("\033[r");

            if ($attribute !== 0x07) {
                $this->fillArea($bottomRow - $lines + 1, $leftCol, $width, $lines, $attribute);
            }

            $this->setCursorPosition($origRow, $origCol);
            return;
        }

        $this->fillArea($bottomRow - $lines + 1, $leftCol, $width, $lines, $attribute);
        $this->setCursorPosition($origRow, $origCol);
    }

    protected function vgaToAnsi(int $fg, int $bg): string
    {
        $ansiFg = $this->vgaToAnsi256[$fg] ?? 7;
        $ansiBg = $this->vgaToAnsi256[$bg] ?? 0;

        return sprintf("\033[38;5;%d;48;5;%dm", $ansiFg, $ansiBg);
    }

    private function rgbToAnsi256(ColorInterface $color): int
    {
        $r = $color->red();
        $g = $color->green();
        $b = $color->blue();

        if ($r === $g && $g === $b) {
            if ($r < 8) {
                return 16;
            }
            if ($r > 248) {
                return 231;
            }
            return (int) round((($r - 8) / 247) * 24) + 232;
        }

        $r6 = (int) round($r / 255 * 5);
        $g6 = (int) round($g / 255 * 5);
        $b6 = (int) round($b / 255 * 5);
        return 16 + (36 * $r6) + (6 * $g6) + $b6;
    }

    public function flushIfNeeded(): void
    {
        if ($this->outputBuffer === '') {
            return;
        }

        // Write all buffered output at once
        $this->writeOutput($this->outputBuffer);
        $this->outputBuffer = '';
    }

    public function setCurrentAttribute(int $attribute): void
    {
        $this->currentAttribute = $attribute;
    }

    public function getCurrentAttribute(): int
    {
        return $this->currentAttribute;
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

    public function updateVideoMode(VideoTypeInfo $videoTypeInfo): void
    {
        $this->videoTypeInfo = $videoTypeInfo;
        $this->resolveTerminalDimensions();
        $this->updateGraphicScaling($videoTypeInfo);
    }
}
