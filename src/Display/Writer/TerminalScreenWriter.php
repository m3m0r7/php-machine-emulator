<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Display\Pixel\ColorInterface;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

class TerminalScreenWriter implements ScreenWriterInterface
{
    protected int $cursorRow = 0;
    protected int $cursorCol = 0;
    protected int $currentAttribute = 0x07; // Default: white on black

    /** @var string Buffered output for batch writing */
    protected string $outputBuffer = '';

    /** @var array<int,string> */
    private array $screenLines = [];
    private ?string $stopOnScreenSubstr = null;
    private bool $stopOnScreenTriggered = false;
    private int $stopOnScreenTail = 0;
    private int $stopOnScreenTailRemaining = 0;
    private ?int $stopOnScreenMatchedRow = null;
    /** @var array{address:int,length:int}|null */
    private ?array $dumpMemOnStop = null;
    private bool $dumpScreenAllOnStop = false;
    /** @var array{length:int,before:int}|null */
    private ?array $dumpCodeOnStop = null;
    private ?int $dumpStackOnStop = null;
    private bool $dumpPtrStringsOnStop = false;
    private bool $silentOutput = false;

    public function __construct(protected RuntimeInterface $runtime, protected VideoTypeInfo $videoTypeInfo)
    {
        $silentEnv = getenv('PHPME_SILENT_TTY');
        $this->silentOutput = $silentEnv !== false && trim($silentEnv) !== '' && trim($silentEnv) !== '0';

        $this->stopOnScreenSubstr = $this->resolveStopOnScreenSubstr();
        $this->stopOnScreenTail = $this->resolveStopOnScreenTail();
        $this->dumpMemOnStop = $this->resolveDumpMemOnStop();
        $this->dumpScreenAllOnStop = $this->resolveDumpScreenAllOnStop();
        $this->dumpCodeOnStop = $this->resolveDumpCodeOnStop();
        $this->dumpStackOnStop = $this->resolveDumpStackOnStop();
        $this->dumpPtrStringsOnStop = $this->resolveDumpPtrStringsOnStop();
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

    private function resolveStopOnScreenSubstr(): ?string
    {
        $grubEnv = getenv('PHPME_STOP_ON_GRUB_FREE_MAGIC');
        if ($grubEnv !== false && $grubEnv !== '' && $grubEnv !== '0') {
            return 'free magic is broken';
        }

        $env = getenv('PHPME_STOP_ON_SCREEN_SUBSTR');
        if ($env === false) {
            return null;
        }
        $trimmed = trim($env);
        return $trimmed === '' ? null : $trimmed;
    }

    private function resolveDumpMemOnStop(): ?array
    {
        $env = getenv('PHPME_DUMP_MEM_ON_SCREEN_STOP');
        if ($env === false) {
            return null;
        }
        $trimmed = trim($env);
        if ($trimmed === '' || $trimmed === '0') {
            return null;
        }

        // Format: "<address>[:<length>]" where address/length can be decimal or 0x... hex.
        $parts = explode(':', $trimmed, 2);
        $addressStr = trim($parts[0]);
        $lengthStr = isset($parts[1]) ? trim($parts[1]) : '256';

        $address = $this->parseIntEnv($addressStr);
        $length = max(1, min(4096, $this->parseIntEnv($lengthStr)));
        if ($address === null) {
            return null;
        }

        return ['address' => $address & 0xFFFFFFFF, 'length' => $length];
    }

    private function resolveDumpScreenAllOnStop(): bool
    {
        $env = getenv('PHPME_DUMP_SCREEN_ALL_ON_STOP');
        return $env !== false && $env !== '' && $env !== '0';
    }

    private function resolveDumpCodeOnStop(): ?array
    {
        $env = getenv('PHPME_DUMP_CODE_ON_SCREEN_STOP');
        if ($env === false) {
            return null;
        }
        $trimmed = trim($env);
        if ($trimmed === '' || $trimmed === '0') {
            return null;
        }

        $length = $this->parseIntEnv($trimmed) ?? 512;
        $length = max(1, min(4096, $length));

        $beforeEnv = getenv('PHPME_DUMP_CODE_ON_SCREEN_STOP_BEFORE');
        $before = 32;
        if ($beforeEnv !== false) {
            $beforeTrimmed = trim($beforeEnv);
            if ($beforeTrimmed !== '' && $beforeTrimmed !== '0') {
                $before = $this->parseIntEnv($beforeTrimmed) ?? $before;
            }
        }
        $before = max(0, min(4096, $before));

        return ['length' => $length, 'before' => $before];
    }

    private function resolveDumpStackOnStop(): ?int
    {
        $env = getenv('PHPME_DUMP_STACK_ON_SCREEN_STOP');
        if ($env === false) {
            return null;
        }
        $trimmed = trim($env);
        if ($trimmed === '' || $trimmed === '0') {
            return null;
        }
        $length = $this->parseIntEnv($trimmed) ?? 512;
        return max(1, min(4096, $length));
    }

    private function resolveDumpPtrStringsOnStop(): bool
    {
        $env = getenv('PHPME_DUMP_PTR_STRINGS_ON_SCREEN_STOP');
        return $env !== false && $env !== '' && $env !== '0';
    }

    private function resolveStopOnScreenTail(): int
    {
        $env = getenv('PHPME_STOP_ON_SCREEN_SUBSTR_TAIL');
        if ($env === false) {
            return 0;
        }
        $trimmed = trim($env);
        if ($trimmed === '' || $trimmed === '0') {
            return 0;
        }
        $parsed = $this->parseIntEnv($trimmed);
        if ($parsed === null) {
            return 0;
        }
        return max(0, min(4096, $parsed));
    }

    private function parseIntEnv(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^0x[0-9a-fA-F]+$/', $value) === 1) {
            return (int) hexdec(substr($value, 2));
        }
        if (preg_match('/^\\d+$/', $value) === 1) {
            return (int) $value;
        }
        return null;
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
        $saveEnv = getenv('PHPME_DUMP_MEM_ON_SCREEN_STOP_SAVE');
        if ($saveEnv !== false && $saveEnv !== '' && $saveEnv !== '0') {
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
            $before = $this->dumpCodeOnStop['before'];
            $start = max(0, (($linearIp & 0xFFFFFFFF) - $before));
            $this->dumpMemoryToFile(
                'CODE',
                $start,
                $this->dumpCodeOnStop['length'],
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
            $this->dumpMemoryPreview($this->dumpMemOnStop['address'], $this->dumpMemOnStop['length']);
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
        // Buffer the cursor move and dot sequence instead of immediate write
        // ANSI escape sequence: move cursor then draw colored space
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

    protected function vgaToAnsi(int $fg, int $bg): string
    {
        // VGA to ANSI 256-color mapping
        static $vgaToAnsi256 = [
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

        $ansiFg = $vgaToAnsi256[$fg] ?? 7;
        $ansiBg = $vgaToAnsi256[$bg] ?? 0;

        return sprintf("\033[38;5;%d;48;5;%dm", $ansiFg, $ansiBg);
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
}
