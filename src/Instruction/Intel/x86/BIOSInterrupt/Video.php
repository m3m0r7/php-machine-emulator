<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Exception\VideoInterruptException;
use PHPMachineEmulator\Instruction\Intel\ServiceFunction\VideoServiceFunction;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\MemoryAccessorFetchResultInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;
use PHPMachineEmulator\Exception\FaultException;

class Video implements InterruptInterface
{
    private int $currentMode = 0x03;
    private int $cursorRow = 0;
    private int $cursorCol = 0;
    private static bool $vbeInitialized = false;
    private static int $vbeModeInfoAddr = 0x2200;
    private static int $vbeModeListAddr = 0x2000;

    // ANSI escape sequence parser state (static to persist across instances)
    private const ANSI_STATE_NORMAL = 0;
    private const ANSI_STATE_ESC = 1;      // Got ESC (0x1B)
    private const ANSI_STATE_CSI = 2;      // Got ESC [
    private static int $ansiState = self::ANSI_STATE_NORMAL;
    private static string $ansiBuffer = '';

    public function __construct(protected RuntimeInterface $runtime)
    {
    }

    public function process(RuntimeInterface $runtime): void
    {
        $fetchResult = $runtime->memoryAccessor()->fetch(RegisterType::EAX);
        $ah = $fetchResult->asHighBit();
        $al = $fetchResult->asLowBit();
        // Only log visible ASCII characters for teletype output
        if ($ah === 0x0E && $al >= 0x20 && $al < 0x7F) {
            $runtime->option()->logger()->debug(sprintf('PRINT: %s (0x%02X)', chr($al), $al));
        }

        $serviceFunction = VideoServiceFunction::tryFrom($ah);

        match ($serviceFunction) {
            VideoServiceFunction::SET_VIDEO_MODE => $this->setVideoMode($runtime, $fetchResult),
            VideoServiceFunction::TELETYPE_OUTPUT => $this->teletypeOutput($runtime, $fetchResult),
            VideoServiceFunction::SET_CURSOR_SHAPE => null, // stub
            VideoServiceFunction::SET_CURSOR_POSITION => $this->setCursorPosition($runtime),
            VideoServiceFunction::GET_CURSOR_POSITION => $this->getCursorPosition($runtime),
            VideoServiceFunction::SELECT_ACTIVE_DISPLAY_PAGE => null, // stub
            VideoServiceFunction::SET_ACTIVE_DISPLAY_PAGE => null, // stub
            VideoServiceFunction::SCROLL_UP_WINDOW => $this->scrollUpWindow($runtime),
            VideoServiceFunction::SCROLL_DOWN_WINDOW => null, // stub
            VideoServiceFunction::READ_CHARACTER_AND_ATTRIBUTE_AT_CURSOR_POSITION => null, // stub
            VideoServiceFunction::WRITE_CHARACTER_AND_ATTRIBUTE_AT_CURSOR_POSITION => $this->writeCharAndAttr($runtime, $fetchResult),
            VideoServiceFunction::WRITE_CHARACTER_ONLY_AT_CURSOR_POSITION => $this->writeCharOnly($runtime, $fetchResult),
            VideoServiceFunction::SET_COLOR_PALETTE => null, // stub
            VideoServiceFunction::READ_PIXEL => null, // stub
            VideoServiceFunction::WRITE_PIXEL => null, // stub
            VideoServiceFunction::GET_CURRENT_VIDEO_MODE => $this->getCurrentVideoMode($runtime),
            VideoServiceFunction::PALETTE_ATTRIBUTE_CONTROL => $this->handlePaletteControl($runtime, $fetchResult),
            // VBE extensions (0x4Fxx) or unsupported functions
            default => $this->handleExtendedOrUnsupported($runtime, $fetchResult, $ah),
        };
    }

    protected function setVideoMode(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        $videoType = $fetchResult->asLowBit();
        $runtime->option()->logger()->debug(sprintf('Set Video Mode: 0x%02X', $videoType));

        // NOTE: validate video type
        $video = $runtime->video()->supportedVideoModes()[$videoType] ?? null;
        if ($video === null) {
            // unsupported: gracefully ignore to let boot continue
            return;
        }
        $this->currentMode = $videoType;

        $runtime
            ->memoryAccessor()
                        ->writeBySize(
                $runtime->video()->videoTypeFlagAddress(),
                // NOTE: Store width, height, and video type in a single flag address.
                // width: 16 bits (bits 48..63)
                // height: 16 bits (bits 32..47)
                // video type: 8 bits (bits 0..7)
                (($video->width & 0xFFFF) << 48) +
                (($video->height & 0xFFFF) << 32) +
                ($videoType & 0xFF),
                64,
            );

        // Update screen writer with new video mode
        $runtime->context()->screen()->updateVideoMode($video);
    }

    protected function teletypeOutput(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        $char = $fetchResult->asLowBit();

        // ANSI escape sequence state machine
        if (self::$ansiState === self::ANSI_STATE_ESC) {
            if ($char === 0x5B) { // '['
                // ESC [ - CSI sequence start
                self::$ansiState = self::ANSI_STATE_CSI;
                self::$ansiBuffer = '';
                return;
            }
            // Not a CSI sequence, reset and output ESC as-is (or ignore)
            self::$ansiState = self::ANSI_STATE_NORMAL;
            // Fall through to handle the current character
        }

        if (self::$ansiState === self::ANSI_STATE_CSI) {
            // Collecting CSI parameters
            // Parameters are digits 0-9, semicolons, and terminated by a letter
            if (($char >= 0x30 && $char <= 0x3F) || $char === 0x3B) {
                // 0-9, :, ;, <, =, >, ? - parameter bytes
                self::$ansiBuffer .= chr($char);
                return;
            }
            if ($char >= 0x40 && $char <= 0x7E) {
                // Final byte (command)
                $this->executeAnsiCommand($runtime, chr($char), self::$ansiBuffer);
                self::$ansiState = self::ANSI_STATE_NORMAL;
                self::$ansiBuffer = '';
                return;
            }
            // Invalid sequence, reset
            self::$ansiState = self::ANSI_STATE_NORMAL;
            self::$ansiBuffer = '';
            // Fall through to handle the current character
        }

        // Check for ESC character
        if ($char === 0x1B) {
            self::$ansiState = self::ANSI_STATE_ESC;
            return;
        }

        // Handle control characters
        if ($char === 0x0D) {
            // CR - Carriage Return: move to beginning of line
            $this->cursorCol = 0;
            $runtime->context()->screen()->setCursorPosition($this->cursorRow, $this->cursorCol);
            return;
        }
        if ($char === 0x0A) {
            // LF - Line Feed: move to next line
            $this->cursorRow++;
            $runtime->context()->screen()->setCursorPosition($this->cursorRow, $this->cursorCol);
            return;
        }
        if ($char === 0x08) {
            // BS - Backspace: move cursor left
            if ($this->cursorCol > 0) {
                $this->cursorCol--;
                $runtime->context()->screen()->setCursorPosition($this->cursorRow, $this->cursorCol);
            }
            return;
        }
        if ($char === 0x07) {
            // BEL - Bell: just ignore
            return;
        }

        // Regular character: write and advance cursor
        $runtime->context()->screen()->setCursorPosition($this->cursorRow, $this->cursorCol);
        $runtime->context()->screen()->write(chr($char));
        $this->cursorCol++;

        // Handle line wrap based on current video mode width
        $videoMode = $runtime->video()->supportedVideoModes()[$this->currentMode] ?? null;
        $cols = $videoMode?->width ?? 80;
        if ($this->cursorCol >= $cols) {
            $this->cursorCol = 0;
            $this->cursorRow++;
        }
    }

    /**
     * Execute an ANSI CSI command.
     */
    protected function executeAnsiCommand(RuntimeInterface $runtime, string $command, string $params): void
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
                $this->cursorRow = max(0, $row);
                $this->cursorCol = max(0, $col);
                $runtime->context()->screen()->setCursorPosition($this->cursorRow, $this->cursorCol);
                break;

            case 'A': // CUU - Cursor Up
                $n = $args[0] ?? 1;
                $this->cursorRow = max(0, $this->cursorRow - $n);
                $runtime->context()->screen()->setCursorPosition($this->cursorRow, $this->cursorCol);
                break;

            case 'B': // CUD - Cursor Down
                $n = $args[0] ?? 1;
                $this->cursorRow += $n;
                $runtime->context()->screen()->setCursorPosition($this->cursorRow, $this->cursorCol);
                break;

            case 'C': // CUF - Cursor Forward
                $n = $args[0] ?? 1;
                $this->cursorCol += $n;
                $runtime->context()->screen()->setCursorPosition($this->cursorRow, $this->cursorCol);
                break;

            case 'D': // CUB - Cursor Back
                $n = $args[0] ?? 1;
                $this->cursorCol = max(0, $this->cursorCol - $n);
                $runtime->context()->screen()->setCursorPosition($this->cursorRow, $this->cursorCol);
                break;

            case 'J': // ED - Erase in Display
                $n = $args[0] ?? 0;
                if ($n === 2) {
                    // Clear entire screen
                    $runtime->context()->screen()->clear();
                    $this->cursorRow = 0;
                    $this->cursorCol = 0;
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

    protected function setCursorPosition(RuntimeInterface $runtime): void
    {
        // DH = row, DL = column
        $row = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asHighBit();
        $col = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        $this->cursorRow = $row;
        $this->cursorCol = $col;
        $runtime->option()->logger()->debug(sprintf('SET_CURSOR_POSITION: row=%d, col=%d', $row, $col));
        // Update the screen writer's cursor position
        $runtime->context()->screen()->setCursorPosition($row, $col);
    }

    protected function getCursorPosition(RuntimeInterface $runtime): void
    {
        $edx = ($this->cursorRow << 8) | ($this->cursorCol & 0xFF);
        $runtime->memoryAccessor()->write16Bit(RegisterType::EDX, $edx);
        // BH = page number (0), BL = attribute. Set BX to 0 for page 0
        $runtime->memoryAccessor()->write16Bit(RegisterType::EBX, 0);
    }

    protected function getCurrentVideoMode(RuntimeInterface $runtime): void
    {
        // INT 10h AH=0Fh - Get Current Video Mode
        // Returns:
        //   AL = current video mode
        //   AH = number of screen columns
        //   BH = active display page
        $videoMode = $runtime->video()->supportedVideoModes()[$this->currentMode] ?? null;
        $cols = $videoMode?->width ?? 80;

        $axValue = ($cols << 8) | ($this->currentMode & 0xFF);
        $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $axValue);
        // BH = current page (0)
        $runtime->memoryAccessor()->writeToHighBit(RegisterType::EBX, 0);
    }

    protected function handlePaletteControl(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        // INT 10h AH=10h - Palette/Attribute Control
        // AL=00h: Set individual palette register
        // AL=01h: Set overscan (border) color
        // AL=02h: Set all palette registers
        // AL=03h: Toggle intensity/blinking bit (BL=0 enable intensity, BL=1 enable blinking)
        // We simply stub these operations for now
        $al = $fetchResult->asLowBit();
        $runtime->option()->logger()->debug(sprintf('Palette control: AL=0x%02X (stub)', $al));
    }

    protected function scrollUpWindow(RuntimeInterface $runtime): void
    {
        // INT 10h AH=06h - Scroll Up Window
        // AL = number of lines to scroll (0 = clear entire window)
        // BH = attribute for blank lines
        // CH,CL = row,column of upper left corner
        // DH,DL = row,column of lower right corner
        $al = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
        $bx = $runtime->memoryAccessor()->fetch(RegisterType::EBX)->asBytesBySize(16);
        $attribute = ($bx >> 8) & 0xFF; // BH = attribute for blank lines
        $cx = $runtime->memoryAccessor()->fetch(RegisterType::ECX)->asBytesBySize(16);
        $dx = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asBytesBySize(16);

        $topRow = ($cx >> 8) & 0xFF;    // CH
        $leftCol = $cx & 0xFF;           // CL
        $bottomRow = ($dx >> 8) & 0xFF;  // DH
        $rightCol = $dx & 0xFF;          // DL

        $width = $rightCol - $leftCol + 1;
        $height = $bottomRow - $topRow + 1;

        $runtime->option()->logger()->debug(sprintf(
            'SCROLL_UP: AL=%d, attr=0x%02X, top=%d, left=%d, bottom=%d, right=%d',
            $al, $attribute, $topRow, $leftCol, $bottomRow, $rightCol
        ));

        if ($al === 0) {
            // Clear entire window with the specified attribute
            $runtime->context()->screen()->fillArea($topRow, $leftCol, $width, $height, $attribute);
        }
        // Otherwise, stub for now - actual scrolling would require screen buffer manipulation
    }

    protected function writeCharAndAttr(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        // INT 10h AH=09h - Write Character and Attribute at Cursor Position
        // AL = character to write
        // BH = page number
        // BL = attribute (text mode) or color (graphics mode)
        // CX = number of times to write character
        $char = chr($fetchResult->asLowBit());
        $bx = $runtime->memoryAccessor()->fetch(RegisterType::EBX)->asBytesBySize(16);
        $attribute = $bx & 0xFF; // BL = attribute
        $count = $runtime->memoryAccessor()->fetch(RegisterType::ECX)->asBytesBySize(16);

        $runtime->option()->logger()->debug(sprintf('WRITE_CHAR_ATTR: char=0x%02X attr=0x%02X count=%d', ord($char), $attribute, $count));

        // Write character at current cursor position with attribute (doesn't advance cursor)
        $runtime->context()->screen()->writeCharAtCursor($char, $count, $attribute);
    }

    protected function writeCharOnly(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        // INT 10h AH=0Ah - Write Character Only at Cursor Position
        // AL = character to write
        // BH = page number
        // CX = number of times to write character
        $char = chr($fetchResult->asLowBit());
        $count = $runtime->memoryAccessor()->fetch(RegisterType::ECX)->asBytesBySize(16);

        // Write character at current cursor position (doesn't advance cursor)
        $runtime->context()->screen()->writeCharAtCursor($char, $count);
    }

    protected function handleVbe(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        $ax = $fetchResult->asByte();
        $ah = ($ax >> 8) & 0xFF;
        $al = $ax & 0xFF;
        $ma = $runtime->memoryAccessor();

        if (!self::$vbeInitialized) {
            $this->initVbeStructures($runtime);
            self::$vbeInitialized = true;
        }

        if ($al !== 0x4F) {
            $ma->write16Bit(RegisterType::AX, 0x014F);
            return;
        }

        $func = $ah;
        if ($func === 0x00) {
            $this->vbeGetInfo($runtime);
            return;
        }
        if ($func === 0x01) {
            $this->vbeGetModeInfo($runtime);
            return;
        }
        if ($func === 0x02) {
            $this->vbeSetMode($runtime);
            return;
        }
        if ($func === 0x03) {
            $this->vbeGetCurrentMode($runtime);
            return;
        }

        $ma->write16Bit(RegisterType::AX, 0x014F);
    }

    private function initVbeStructures(RuntimeInterface $runtime): void
    {
        // Mode list at 0x2000: terminate with 0xFFFF
        $list = [0x118, 0x141, 0xFFFF];
        $addr = self::$vbeModeListAddr;
        foreach ($list as $mode) {
            $this->writeMemory16($runtime, $addr, $mode & 0xFFFF);
            $addr += 2;
        }

        // Mode info block at 0x2200 for mode 0x141 (1024x768x32)
        $base = self::$vbeModeInfoAddr;
        $this->writeMemory16($runtime, $base + 0x00, 0x009B); // attributes: mode supported, color, graphics, LFB
        $this->writeMemory8($runtime, $base + 0x02, 0); // window A
        $this->writeMemory8($runtime, $base + 0x03, 0);
        $this->writeMemory16($runtime, $base + 0x04, 0);
        $this->writeMemory16($runtime, $base + 0x06, 0);
        $this->writeMemory16($runtime, $base + 0x08, 0);
        $this->writeMemory16($runtime, $base + 0x0A, 0);
        $this->writeMemory8($runtime, $base + 0x0C, 0);
        $this->writeMemory8($runtime, $base + 0x0D, 0);
        $this->writeMemory16($runtime, $base + 0x12, 1024); // X
        $this->writeMemory16($runtime, $base + 0x14, 768);  // Y
        $this->writeMemory8($runtime, $base + 0x18, 32);   // bpp
        $this->writeMemory8($runtime, $base + 0x19, 1);    // memory model packed pixel
        $this->writeMemory16($runtime, $base + 0x10, 1);   // number of planes
        $this->writeMemory16($runtime, $base + 0x1A, 32);  // bytes per scan line / pixel byte?
        $this->writeMemory16($runtime, $base + 0x1A, 4096); // line length
        $this->writeMemory16($runtime, $base + 0x1C, 32);  // image base?
        $this->writeMemory16($runtime, $base + 0x1E, 1);   // pages
        $this->writeMemory8($runtime, $base + 0x1F, 8);    // red mask
        $this->writeMemory8($runtime, $base + 0x20, 16);   // red position
        $this->writeMemory8($runtime, $base + 0x21, 8);    // green mask
        $this->writeMemory8($runtime, $base + 0x22, 8);    // green pos
        $this->writeMemory8($runtime, $base + 0x23, 8);    // blue mask
        $this->writeMemory8($runtime, $base + 0x24, 0);    // blue pos
        $this->writeMemory8($runtime, $base + 0x25, 8);    // rsvd mask
        $this->writeMemory8($runtime, $base + 0x26, 0);
        $this->writeMemory8($runtime, $base + 0x27, 0);    // direct color
        $this->writeMemory32($runtime, $base + 0x28, 0xE0000000); // phys base
        $this->writeMemory16($runtime, $base + 0x2E, 1);   // lin bytes per scan line in 32bpp
        $this->writeMemory16($runtime, $base + 0x2E, 4096);
    }

    private function vbeGetInfo(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $addr = $this->segmentOffsetAddress($runtime, RegisterType::ES, $ma->fetch(RegisterType::EDI)->asBytesBySize($runtime->context()->cpu()->addressSize()));
        // Zero 512 bytes
        for ($i = 0; $i < 512; $i++) {
            $this->writeMemory8($runtime, $addr + $i, 0);
        }
        $this->writeMemory8($runtime, $addr, ord('V'));
        $this->writeMemory8($runtime, $addr + 1, ord('E'));
        $this->writeMemory8($runtime, $addr + 2, ord('S'));
        $this->writeMemory8($runtime, $addr + 3, ord('A'));
        $this->writeMemory16($runtime, $addr + 4, 0x0300); // version
        // OEM, vendor/product strings omitted
        // Video mode pointer
        $this->writeMemory16($runtime, $addr + 0x0E, self::$vbeModeListAddr & 0xF); // offset
        $this->writeMemory16($runtime, $addr + 0x10, (self::$vbeModeListAddr >> 4) & 0xFFFF); // segment
        $this->writeMemory16($runtime, $addr + 0x12, 0x0080); // total memory (64KB blocks) -> 8MB
        $ma->write16Bit(RegisterType::AX, 0x004F);
    }

    private function vbeGetModeInfo(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $mode = $ma->fetch(RegisterType::ECX)->asBytesBySize(16) & 0xFFFF;
        $dest = $this->segmentOffsetAddress($runtime, RegisterType::ES, $ma->fetch(RegisterType::EDI)->asBytesBySize($runtime->context()->cpu()->addressSize()));
        // Only one mode: 0x141
        if ($mode !== 0x141) {
            $ma->write16Bit(RegisterType::AX, 0x014F);
            return;
        }
        // copy mode info block
        for ($i = 0; $i < 256; $i++) {
            $byte = $this->readMemory8($runtime, self::$vbeModeInfoAddr + $i);
            $this->writeMemory8($runtime, $dest + $i, $byte);
        }
        $ma->write16Bit(RegisterType::AX, 0x004F);
    }

    private function vbeSetMode(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $mode = $ma->fetch(RegisterType::EBX)->asBytesBySize(16) & 0x1FF;
        if ($mode === 0x141) {
            $this->currentMode = $mode;
            $ma->write16Bit(RegisterType::AX, 0x004F);
            return;
        }
        $ma->write16Bit(RegisterType::AX, 0x014F);
    }

    private function vbeGetCurrentMode(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $ma->write16Bit(RegisterType::BX, $this->currentMode & 0x1FF);
        $ma->write16Bit(RegisterType::AX, 0x004F);
    }

    /**
     * Handle extended or unsupported video functions.
     */
    protected function handleExtendedOrUnsupported(
        RuntimeInterface $runtime,
        MemoryAccessorFetchResultInterface $fetchResult,
        int $ah
    ): void {
        // VBE functions (AH=0x4F)
        if ($ah === 0x4F) {
            $this->handleVbe($runtime, $fetchResult);
            return;
        }

        // AH=0x1C: Video State Save/Restore (stub - just return function not supported)
        if ($ah === 0x1C) {
            $al = $fetchResult->asLowBit();
            $ma = $runtime->memoryAccessor();

            // AL=0x00: Get state buffer size
            // AL=0x01: Save state
            // AL=0x02: Restore state
            // Return AL=0x1C (function supported) with minimal implementation
            $ma->writeToLowBit(RegisterType::EAX, 0x1C);

            if ($al === 0x00) {
                // Return buffer size in BX (64 bytes / 64 = 1 block)
                $ma->write16Bit(RegisterType::EBX, 0x0001);
            }

            $runtime->option()->logger()->debug(sprintf('INT 10h AH=0x1C AL=0x%02X (Video State): stub', $al));
            return;
        }

        // Other unsupported functions - log and ignore
        $runtime->option()->logger()->debug(sprintf('INT 10h AH=0x%02X: unsupported function', $ah));
    }
}
