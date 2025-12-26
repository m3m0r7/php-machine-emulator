<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Instruction\Intel\ServiceFunction\VideoServiceFunction;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\Device\VideoContextInterface;
use PHPMachineEmulator\Runtime\MemoryAccessorFetchResultInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoColorType;
use PHPMachineEmulator\Video\VideoTypeInfo;

class Video implements InterruptInterface
{
    private ?int $traceInt10CallsLimit = null;
    private int $traceInt10Calls = 0;

    private bool $vbeInitialized = false;
    private int $vbeModeInfoAddr = 0x2200;
    private int $vbeModeListAddr = 0x2000;

    public function __construct(protected RuntimeInterface $runtime)
    {
    }

    /**
     * Get the video context from DeviceManager.
     *
     * @throws \RuntimeException if video context is not registered
     */
    protected function videoContext(): VideoContextInterface
    {
        return $this->runtime->context()->devices()->video();
    }

    public function process(RuntimeInterface $runtime): void
    {
        $fetchResult = $runtime->memoryAccessor()->fetch(RegisterType::EAX);
        $ah = $fetchResult->asHighBit();
        $al = $fetchResult->asLowBit();

        $this->maybeTraceInt10Call($runtime, $ah, $al);

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
            VideoServiceFunction::WRITE_STRING => $this->writeString($runtime, $fetchResult),
            // VBE extensions (0x4Fxx) or unsupported functions
            default => $this->handleExtendedOrUnsupported($runtime, $fetchResult, $ah),
        };
    }

    private function traceInt10CallsLimit(RuntimeInterface $runtime): int
    {
        if ($this->traceInt10CallsLimit !== null) {
            return $this->traceInt10CallsLimit;
        }

        $limit = $runtime->logicBoard()->debug()->trace()->traceInt10CallsLimit;
        $this->traceInt10CallsLimit = max(0, $limit);
        return $this->traceInt10CallsLimit;
    }

    private function maybeTraceInt10Call(RuntimeInterface $runtime, int $ah, int $al): void
    {
        $limit = $this->traceInt10CallsLimit($runtime);
        if ($limit <= 0 || $this->traceInt10Calls >= $limit) {
            return;
        }
        $this->traceInt10Calls++;

        $ma = $runtime->memoryAccessor();
        $ax = $ma->fetch(RegisterType::EAX)->asBytesBySize(16) & 0xFFFF;
        $bx = $ma->fetch(RegisterType::EBX)->asBytesBySize(16) & 0xFFFF;
        $cx = $ma->fetch(RegisterType::ECX)->asBytesBySize(16) & 0xFFFF;
        $dx = $ma->fetch(RegisterType::EDX)->asBytesBySize(16) & 0xFFFF;
        $runtime->option()->logger()->warning(sprintf(
            'INT 10h: AH=0x%02X AL=0x%02X AX=0x%04X BX=0x%04X CX=0x%04X DX=0x%04X (%d/%d)',
            $ah & 0xFF,
            $al & 0xFF,
            $ax,
            $bx,
            $cx,
            $dx,
            $this->traceInt10Calls,
            $limit,
        ));
    }

    /**
     * INT 10h AH=13h - Write String.
     *
     * AL:
     *   00h: chars only, attribute in BL, update cursor
     *   01h: chars only, attribute in BL, do not update cursor
     *   02h: char+attr pairs, update cursor
     *   03h: char+attr pairs, do not update cursor
     *
     * BH: page (ignored)
     * BL: attribute (when AL bit1=0)
     * CX: length (characters)
     * DH: row, DL: col
     * ES:BP -> string
     */
    protected function writeString(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        $ax = $fetchResult->asBytesBySize(16);
        $al = $ax & 0xFF;

        $len = $runtime->memoryAccessor()->fetch(RegisterType::ECX)->asBytesBySize(16) & 0xFFFF;
        if ($len <= 0) {
            return;
        }

        if ($runtime->logicBoard()->debug()->trace()->stopOnInt10WriteString) {
            $dx = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asBytesBySize(16) & 0xFFFF;
            $row = ($dx >> 8) & 0xFF;
            $col = $dx & 0xFF;
            $runtime->option()->logger()->warning(sprintf(
                'INT10: write string AL=0x%02X len=%d row=%d col=%d',
                $al,
                $len,
                $row,
                $col,
            ));
            throw new HaltException('Stopped by PHPME_STOP_ON_INT10_WRITE_STRING');
        }

        $bx = $runtime->memoryAccessor()->fetch(RegisterType::EBX)->asBytesBySize(16) & 0xFFFF;
        $defaultAttr = $bx & 0xFF; // BL

        $dx = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asBytesBySize(16) & 0xFFFF;
        $row = ($dx >> 8) & 0xFF; // DH
        $col = $dx & 0xFF;        // DL

        $addrSize = $runtime->context()->cpu()->addressSize();
        $bp = $runtime->memoryAccessor()->fetch(RegisterType::EBP)->asBytesBySize($addrSize);
        $src = $this->segmentOffsetAddress($runtime, RegisterType::ES, $bp);

        $hasAttrInString = ($al & 0x02) !== 0;
        $updateCursor = ($al & 0x01) === 0;

        $videoContext = $this->videoContext();
        $prevRow = $videoContext->getCursorRow();
        $prevCol = $videoContext->getCursorCol();

        $screenWidth = 80;
        $mode = $videoContext->getCurrentMode();
        $videoTypeInfo = $runtime->video()->supportedVideoModes()[$mode] ?? null;
        if ($videoTypeInfo !== null && $videoTypeInfo->isTextMode) {
            $screenWidth = $videoTypeInfo->width;
        }

        $posRow = $row;
        $posCol = $col;

        for ($i = 0; $i < $len; $i++) {
            if ($hasAttrInString) {
                $charCode = $this->readMemory8($runtime, $src + ($i * 2));
                $attr = $this->readMemory8($runtime, $src + ($i * 2) + 1) & 0xFF;
            } else {
                $charCode = $this->readMemory8($runtime, $src + $i);
                $attr = $defaultAttr;
            }

            $char = chr($charCode & 0xFF);
            $runtime->context()->screen()->writeCharAt($posRow, $posCol, $char, $attr);

            $posCol++;
            if ($posCol >= $screenWidth) {
                $posCol = 0;
                $posRow++;
            }
        }

        if ($updateCursor) {
            $videoContext->setCursorPosition($posRow, $posCol);
            $runtime->context()->screen()->setCursorPosition($posRow, $posCol);
        } else {
            $videoContext->setCursorPosition($prevRow, $prevCol);
        }
    }

    protected function setVideoMode(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        $videoType = $fetchResult->asLowBit();
        $runtime->option()->logger()->debug(sprintf('Set Video Mode: 0x%02X', $videoType));

        if ($runtime->logicBoard()->debug()->trace()->stopOnSetVideoMode) {
            $runtime->option()->logger()->warning(sprintf('INT10: set video mode 0x%02X', $videoType));
            throw new HaltException('Stopped by PHPME_STOP_ON_SET_VIDEO_MODE');
        }

        // NOTE: validate video type
        $video = $runtime->video()->supportedVideoModes()[$videoType] ?? null;
        if ($video === null) {
            // unsupported: gracefully ignore to let boot continue
            return;
        }
        $this->videoContext()->setCurrentMode($videoType);
        $this->videoContext()->disableLinearFramebuffer();

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
        $videoContext = $this->videoContext();

        // Use ANSI parser from VideoContext
        if ($videoContext->ansiParser()->processChar($char, $runtime, $videoContext)) {
            // Character was consumed by ANSI parser
            return;
        }

        // Handle control characters
        if ($char === 0x0D) {
            // CR - Carriage Return: move to beginning of line
            $videoContext->setCursorPosition($videoContext->getCursorRow(), 0);
            $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
            return;
        }
        if ($char === 0x0A) {
            // LF - Line Feed: move to next line
            $videoContext->setCursorPosition($videoContext->getCursorRow() + 1, $videoContext->getCursorCol());
            $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
            return;
        }
        if ($char === 0x08) {
            // BS - Backspace: move cursor left
            if ($videoContext->getCursorCol() > 0) {
                $videoContext->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol() - 1);
                $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
            }
            return;
        }
        if ($char === 0x07) {
            // BEL - Bell: just ignore
            return;
        }

        // Regular character: write and advance cursor
        $runtime->context()->screen()->setCursorPosition($videoContext->getCursorRow(), $videoContext->getCursorCol());
        $runtime->context()->screen()->write(chr($char));
        $newCol = $videoContext->getCursorCol() + 1;

        // Handle line wrap based on current video mode width
        $videoMode = $runtime->video()->supportedVideoModes()[$videoContext->getCurrentMode()] ?? null;
        $cols = $videoMode?->width ?? 80;
        if ($newCol >= $cols) {
            $videoContext->setCursorPosition($videoContext->getCursorRow() + 1, 0);
        } else {
            $videoContext->setCursorPosition($videoContext->getCursorRow(), $newCol);
        }
    }

    protected function setCursorPosition(RuntimeInterface $runtime): void
    {
        // DH = row, DL = column
        $row = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asHighBit();
        $col = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asLowBit();
        $this->videoContext()->setCursorPosition($row, $col);
        $runtime->option()->logger()->debug(sprintf('SET_CURSOR_POSITION: row=%d, col=%d', $row, $col));
        // Update the screen writer's cursor position
        $runtime->context()->screen()->setCursorPosition($row, $col);
    }

    protected function getCursorPosition(RuntimeInterface $runtime): void
    {
        $videoContext = $this->videoContext();
        $edx = ($videoContext->getCursorRow() << 8) | ($videoContext->getCursorCol() & 0xFF);
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
        $currentMode = $this->videoContext()->getCurrentMode();
        $videoMode = $runtime->video()->supportedVideoModes()[$currentMode] ?? null;
        $cols = $videoMode?->width ?? 80;

        $axValue = ($cols << 8) | ($currentMode & 0xFF);
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
        $charCode = $fetchResult->asLowBit();
        $bx = $runtime->memoryAccessor()->fetch(RegisterType::EBX)->asBytesBySize(16);
        $attribute = $bx & 0xFF; // BL = attribute
        $count = $runtime->memoryAccessor()->fetch(RegisterType::ECX)->asBytesBySize(16);

        $runtime->option()->logger()->debug(sprintf('WRITE_CHAR_ATTR: char=0x%02X attr=0x%02X count=%d', $charCode, $attribute, $count));

        // SYSLINUX uses AH=09h to output ANSI escape sequences
        // Process through ANSI parser first
        $videoContext = $this->videoContext();
        if ($videoContext->ansiParser()->processChar($charCode, $runtime, $videoContext)) {
            // Character was consumed by ANSI parser (part of escape sequence)
            return;
        }

        // Write character at current cursor position with attribute (doesn't advance cursor)
        $char = chr($charCode);
        $runtime->context()->screen()->writeCharAtCursor($char, $count, $attribute);
    }

    protected function writeCharOnly(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        // INT 10h AH=0Ah - Write Character Only at Cursor Position
        // AL = character to write
        // BH = page number
        // CX = number of times to write character
        $charCode = $fetchResult->asLowBit();
        $count = $runtime->memoryAccessor()->fetch(RegisterType::ECX)->asBytesBySize(16);

        // Process through ANSI parser first
        $videoContext = $this->videoContext();
        if ($videoContext->ansiParser()->processChar($charCode, $runtime, $videoContext)) {
            // Character was consumed by ANSI parser (part of escape sequence)
            return;
        }

        // Write character at current cursor position (doesn't advance cursor)
        $char = chr($charCode);
        $runtime->context()->screen()->writeCharAtCursor($char, $count);
    }

    protected function handleVbe(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        // VBE entrypoint uses AX=0x4Fxx where:
        //   AH = 0x4F (VBE), AL = function number
        $ax = $fetchResult->asBytesBySize(16);
        $ah = ($ax >> 8) & 0xFF;
        $func = $ax & 0xFF;
        $ma = $runtime->memoryAccessor();

        if (!$this->vbeInitialized) {
            $this->initVbeStructures($runtime);
            $this->vbeInitialized = true;
        }

        // If this wasn't actually a VBE call, return "function call failed".
        if ($ah !== 0x4F) {
            $ma->write16Bit(RegisterType::EAX, 0x014F);
            return;
        }

        match ($func) {
            0x00 => $this->vbeGetInfo($runtime),
            0x01 => $this->vbeGetModeInfo($runtime),
            0x02 => $this->vbeSetMode($runtime),
            0x03 => $this->vbeGetCurrentMode($runtime),
            default => $ma->write16Bit(RegisterType::EAX, 0x014F),
        };
    }

    private function initVbeStructures(RuntimeInterface $runtime): void
    {
        // Mode list at 0x2000: terminate with 0xFFFF
        $list = [0x141, 0xFFFF];
        $addr = $this->vbeModeListAddr;
        foreach ($list as $mode) {
            $this->writeMemory16($runtime, $addr, $mode & 0xFFFF);
            $addr += 2;
        }

        // Mode info block at 0x2200 for mode 0x141 (1024x768x32, LFB at 0xE0000000)
        $base = $this->vbeModeInfoAddr;
        // Clear 256 bytes.
        for ($i = 0; $i < 256; $i++) {
            $this->writeMemory8($runtime, $base + $i, 0);
        }

        $width = 1024;
        $height = 768;
        $bytesPerPixel = 4;
        $bytesPerScanLine = $width * $bytesPerPixel;

        // VBE ModeInfoBlock (VBE 3.0, 256 bytes)
        // https://pdos.csail.mit.edu/6.828/2018/readings/hardware/vbe3.pdf
        $this->writeMemory16($runtime, $base + 0x00, 0x009B); // ModeAttributes: supported + graphics + color + LFB
        $this->writeMemory8($runtime, $base + 0x02, 0x00);   // WinAAttributes
        $this->writeMemory8($runtime, $base + 0x03, 0x00);   // WinBAttributes
        $this->writeMemory16($runtime, $base + 0x04, 0x0000); // WinGranularity
        $this->writeMemory16($runtime, $base + 0x06, 0x0000); // WinSize
        $this->writeMemory16($runtime, $base + 0x08, 0x0000); // WinASegment
        $this->writeMemory16($runtime, $base + 0x0A, 0x0000); // WinBSegment
        $this->writeMemory32($runtime, $base + 0x0C, 0x00000000); // WinFuncPtr
        $this->writeMemory16($runtime, $base + 0x10, $bytesPerScanLine & 0xFFFF); // BytesPerScanLine
        $this->writeMemory16($runtime, $base + 0x12, $width & 0xFFFF); // XResolution
        $this->writeMemory16($runtime, $base + 0x14, $height & 0xFFFF); // YResolution
        $this->writeMemory8($runtime, $base + 0x16, 8);  // XCharSize
        $this->writeMemory8($runtime, $base + 0x17, 16); // YCharSize
        $this->writeMemory8($runtime, $base + 0x18, 1);  // NumberOfPlanes
        $this->writeMemory8($runtime, $base + 0x19, 32); // BitsPerPixel
        $this->writeMemory8($runtime, $base + 0x1A, 1);  // NumberOfBanks
        $this->writeMemory8($runtime, $base + 0x1B, 0x06); // MemoryModel: Direct Color
        $this->writeMemory8($runtime, $base + 0x1C, 0);  // BankSize
        $this->writeMemory8($runtime, $base + 0x1D, 0);  // NumberOfImagePages
        $this->writeMemory8($runtime, $base + 0x1E, 0);  // Reserved1

        // Direct Color fields (legacy/banked)
        $this->writeMemory8($runtime, $base + 0x1F, 8);  // RedMaskSize
        $this->writeMemory8($runtime, $base + 0x20, 16); // RedFieldPosition
        $this->writeMemory8($runtime, $base + 0x21, 8);  // GreenMaskSize
        $this->writeMemory8($runtime, $base + 0x22, 8);  // GreenFieldPosition
        $this->writeMemory8($runtime, $base + 0x23, 8);  // BlueMaskSize
        $this->writeMemory8($runtime, $base + 0x24, 0);  // BlueFieldPosition
        $this->writeMemory8($runtime, $base + 0x25, 8);  // RsvdMaskSize
        $this->writeMemory8($runtime, $base + 0x26, 24); // RsvdFieldPosition
        $this->writeMemory8($runtime, $base + 0x27, 0);  // DirectColorModeInfo

        // Linear Frame Buffer
        $this->writeMemory32($runtime, $base + 0x28, 0xE0000000); // PhysBasePtr
        $this->writeMemory32($runtime, $base + 0x2C, 0x00000000); // OffScreenMemOffset
        $this->writeMemory16($runtime, $base + 0x30, 0x0000); // OffScreenMemSize

        // VBE 3.0 linear-mode variants
        $this->writeMemory16($runtime, $base + 0x32, $bytesPerScanLine & 0xFFFF); // LinBytesPerScanLine
        $this->writeMemory8($runtime, $base + 0x34, 0); // BnkNumberOfImagePages
        $this->writeMemory8($runtime, $base + 0x35, 0); // LinNumberOfImagePages
        $this->writeMemory8($runtime, $base + 0x36, 8);  // LinRedMaskSize
        $this->writeMemory8($runtime, $base + 0x37, 16); // LinRedFieldPosition
        $this->writeMemory8($runtime, $base + 0x38, 8);  // LinGreenMaskSize
        $this->writeMemory8($runtime, $base + 0x39, 8);  // LinGreenFieldPosition
        $this->writeMemory8($runtime, $base + 0x3A, 8);  // LinBlueMaskSize
        $this->writeMemory8($runtime, $base + 0x3B, 0);  // LinBlueFieldPosition
        $this->writeMemory8($runtime, $base + 0x3C, 8);  // LinRsvdMaskSize
        $this->writeMemory8($runtime, $base + 0x3D, 24); // LinRsvdFieldPosition
        $this->writeMemory32($runtime, $base + 0x3E, 0x00000000); // MaxPixelClock
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
        $this->writeMemory16($runtime, $addr + 0x0E, $this->vbeModeListAddr & 0xF); // offset
        $this->writeMemory16($runtime, $addr + 0x10, ($this->vbeModeListAddr >> 4) & 0xFFFF); // segment
        $this->writeMemory16($runtime, $addr + 0x12, 0x0080); // total memory (64KB blocks) -> 8MB
        $ma->write16Bit(RegisterType::EAX, 0x004F);
    }

    private function vbeGetModeInfo(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $mode = $ma->fetch(RegisterType::ECX)->asBytesBySize(16) & 0xFFFF;
        $dest = $this->segmentOffsetAddress($runtime, RegisterType::ES, $ma->fetch(RegisterType::EDI)->asBytesBySize($runtime->context()->cpu()->addressSize()));
        // Only one mode: 0x141
        if ($mode !== 0x141) {
            $ma->write16Bit(RegisterType::EAX, 0x014F);
            return;
        }
        // copy mode info block
        for ($i = 0; $i < 256; $i++) {
            $byte = $this->readMemory8($runtime, $this->vbeModeInfoAddr + $i);
            $this->writeMemory8($runtime, $dest + $i, $byte);
        }
        $ma->write16Bit(RegisterType::EAX, 0x004F);
    }

    private function vbeSetMode(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $mode = $ma->fetch(RegisterType::EBX)->asBytesBySize(16) & 0x1FF;
        if ($mode === 0x141) {
            $this->videoContext()->setCurrentMode($mode);
            $this->videoContext()->enableLinearFramebuffer(
                0xE0000000,
                1024,
                768,
                1024 * 4,
                32,
            );

            $runtime->context()->screen()->updateVideoMode(
                new VideoTypeInfo(1024, 768, 1 << 24, VideoColorType::COLOR),
            );
            $ma->write16Bit(RegisterType::EAX, 0x004F);

            if ($runtime->logicBoard()->debug()->trace()->stopOnVbeSetMode) {
                $runtime->option()->logger()->warning(sprintf('VBE: set mode 0x%X', $mode));
                throw new HaltException('Stopped by PHPME_STOP_ON_VBE_SETMODE');
            }
            return;
        }
        $ma->write16Bit(RegisterType::EAX, 0x014F);
    }

    private function vbeGetCurrentMode(RuntimeInterface $runtime): void
    {
        $ma = $runtime->memoryAccessor();
        $ma->write16Bit(RegisterType::EBX, $this->videoContext()->getCurrentMode() & 0x1FF);
        $ma->write16Bit(RegisterType::EAX, 0x004F);
    }

    /**
     * Calculate segment:offset to linear address.
     */
    protected function segmentOffsetAddress(RuntimeInterface $runtime, RegisterType $segment, int $offset): int
    {
        $addressSize = $runtime->context()->cpu()->addressSize();
        $offsetMask = $addressSize === 32 ? 0xFFFFFFFF : 0xFFFF;
        $linearMask = $runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
        $selector = $runtime->memoryAccessor()->fetch($segment)->asBytesBySize(16) & 0xFFFF;
        $effOffset = $offset & $offsetMask;

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $segBase = $this->selectorBaseAddress($runtime, $selector);
            if ($segBase !== null) {
                return ($segBase + $effOffset) & $linearMask;
            }
            return $effOffset & $linearMask;
        }

        // Big Real Mode (Unreal Mode) support: if we have a cached descriptor, use its base.
        $cached = $runtime->context()->cpu()->getCachedSegmentDescriptor($segment);
        $segBase = $cached['base'] ?? (($selector << 4) & 0xFFFFF);

        return ($segBase + $effOffset) & $linearMask;
    }

    private function selectorBaseAddress(RuntimeInterface $runtime, int $selector): ?int
    {
        $ti = ($selector >> 2) & 0x1;
        if ($ti === 1) {
            $ldtr = $runtime->context()->cpu()->ldtr();
            if (($ldtr['selector'] ?? 0) === 0) {
                return null;
            }
            $tableBase = $ldtr['base'] ?? 0;
            $tableLimit = $ldtr['limit'] ?? 0;
        } else {
            $gdtr = $runtime->context()->cpu()->gdtr();
            $tableBase = $gdtr['base'] ?? 0;
            $tableLimit = $gdtr['limit'] ?? 0;
        }

        $index = ($selector >> 3) & 0x1FFF;
        $descAddr = $tableBase + ($index * 8);
        if ($descAddr + 7 > $tableBase + $tableLimit) {
            return null;
        }

        $b2 = $this->readMemory8($runtime, $descAddr + 2);
        $b3 = $this->readMemory8($runtime, $descAddr + 3);
        $b4 = $this->readMemory8($runtime, $descAddr + 4);
        $b7 = $this->readMemory8($runtime, $descAddr + 7);

        return (($b2) | ($b3 << 8) | ($b4 << 16) | ($b7 << 24)) & 0xFFFFFFFF;
    }

    /**
     * Read 8-bit value from memory.
     */
    protected function readMemory8(RuntimeInterface $runtime, int $address): int
    {
        try {
            $value = $runtime->memoryAccessor()->readRawByte($address);
            if ($value !== null) {
                return $value;
            }
            $memory = $runtime->memory();
            $currentOffset = $memory->offset();
            $memory->setOffset($address);
            $byte = $memory->byte();
            $memory->setOffset($currentOffset);
            return $byte;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Write 8-bit value to memory.
     */
    protected function writeMemory8(RuntimeInterface $runtime, int $address, int $value): void
    {
        try {
            $runtime->memoryAccessor()->writeRawByte($address, $value & 0xFF);
        } catch (\Throwable) {
            // Ignore write errors
        }
    }

    /**
     * Write 16-bit value to memory (little-endian).
     */
    protected function writeMemory16(RuntimeInterface $runtime, int $address, int $value): void
    {
        $this->writeMemory8($runtime, $address, $value & 0xFF);
        $this->writeMemory8($runtime, $address + 1, ($value >> 8) & 0xFF);
    }

    /**
     * Write 32-bit value to memory (little-endian).
     */
    protected function writeMemory32(RuntimeInterface $runtime, int $address, int $value): void
    {
        $this->writeMemory8($runtime, $address, $value & 0xFF);
        $this->writeMemory8($runtime, $address + 1, ($value >> 8) & 0xFF);
        $this->writeMemory8($runtime, $address + 2, ($value >> 16) & 0xFF);
        $this->writeMemory8($runtime, $address + 3, ($value >> 24) & 0xFF);
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
