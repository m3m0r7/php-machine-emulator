<?php

declare(strict_types=1);

namespace PHPMachineEmulator;

use PHPMachineEmulator\Exception\BIOSInvalidException;
use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Exception\StreamReaderException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class BIOS
{
    public const NAME = 'PHPMachineEmulator';
    public const BIOS_ENTRYPOINT = 0x7C00;
    public const READ_SIZE_PER_SECTOR = 512;

    // BDA (BIOS Data Area) address range
    public const BDA_START = 0x400;
    public const BDA_END = 0x4FF;

    // Conventional memory size in KB (640KB)
    public const CONVENTIONAL_MEMORY_KB = 640;

    private ?RuntimeInterface $runtime = null;

    public function __construct(protected MachineInterface $machine)
    {
        if ($this->machine->logicBoard()->media()->primary()->bootType() === BootType::BOOT_SIGNATURE) {
            $this->verifyBIOSSignature();
        }


        $this->initialize();
    }

    public function machine(): MachineInterface
    {
        return $this->machine;
    }

    public function runtime(): RuntimeInterface
    {
        return $this->runtime ??= $this->machine->runtime(self::BIOS_ENTRYPOINT);
    }

    public static function start(MachineInterface $machine): void
    {
        try {
            (new static($machine))
                ->runtime()
                ->start();
        } catch (HaltException) {
            throw new ExitException('Halted', 0);
        } catch (ExitException $e) {
            throw $e;
        }
    }

    /**
     * Initialize BIOS memory areas and CPU registers.
     */
    protected function initialize(): void
    {
        // 1. Allocate CPU registers first (must be done before any register access)
        $this->initializeRegisters();

        // 2. Initialize services (e.g., VideoMemoryService)
        $this->initializeServices();

        // 3. Initialize IVT first (to handle exceptions safely)
        $this->initializeIVT();

        // 4. Initialize ROM area (BIOS identification, default handlers)
        $this->initializeROM();

        // 5. Initialize BDA
        $this->initializeBDA();

        // 6. Initialize boot segment (CS register) - should be last before boot
        $this->initializeBootSegment();

        $this->machine->option()->logger()->info('BIOS initialization completed');
    }

    /**
     * Allocate CPU registers.
     */
    protected function initializeRegisters(): void
    {
        $runtime = $this->runtime();
        $mem = $runtime->memoryAccessor();

        foreach ([...$runtime->register()::map(), $runtime->video()->videoTypeFlagAddress()] as $address) {
            $mem->allocate($address);
            $this->machine->option()->logger()->debug(sprintf('BIOS: Register allocated 0x%03s', decbin($address)));
        }
    }

    /**
     * Initialize architecture services.
     */
    protected function initializeServices(): void
    {
        $runtime = $this->runtime();
        foreach ($runtime->services() as $service) {
            $service->initialize($runtime);
            $this->machine->option()->logger()->debug(sprintf('BIOS: Initialize %s service', get_class($service)));
        }
    }

    /**
     * Initialize CS register for bootable streams.
     */
    protected function initializeBootSegment(): void
    {
        $loadSegment = $this->machine->logicBoard()->media()->primary()->stream()->loadSegment();
        $this->runtime()->memoryAccessor()->write16Bit(RegisterType::CS, $loadSegment);
        $this->machine->option()->logger()->debug(
            sprintf('BIOS: Initialized CS to 0x%04X for bootable stream', $loadSegment)
        );
    }

    /**
     * Initialize BIOS Data Area (BDA) at 0x400-0x4FF.
     * This area contains essential BIOS state information.
     */
    protected function initializeBDA(): void
    {
        $mem = $this->runtime()->memoryAccessor();
        $video = $this->runtime()->video();
        $videoMode = $video->supportedVideoModes()[0x03] ?? null;
        $cols = $videoMode?->width ?? 80;
        $rows = $videoMode?->height ?? 25;

        // ========================================
        // I/O Port Addresses (0x400-0x40F)
        // ========================================
        // 0x400-0x407: COM port base addresses (4 words)
        $mem->writeBySize(0x400, 0x03F8, 16);  // COM1
        $mem->writeBySize(0x402, 0x02F8, 16);  // COM2
        $mem->writeBySize(0x404, 0x03E8, 16);  // COM3
        $mem->writeBySize(0x406, 0x02E8, 16);  // COM4

        // 0x408-0x40D: LPT port base addresses (3 words)
        $mem->writeBySize(0x408, 0x0378, 16);  // LPT1
        $mem->writeBySize(0x40A, 0x0278, 16);  // LPT2
        $mem->writeBySize(0x40C, 0x0000, 16);  // LPT3 (not present)

        // 0x40E-0x40F: EBDA (Extended BIOS Data Area) segment address
        // EBDA is at physical address 0x9FC00, so segment = 0x9FC0
        $mem->writeBySize(0x40E, 0x9FC0, 16);

        // ========================================
        // Equipment & Memory (0x410-0x414)
        // ========================================
        // 0x410-0x411: Equipment list flags
        // Bit 0: Floppy drive(s) present
        // Bit 1: Math coprocessor present
        // Bit 4-5: Initial video mode (10 = 80x25 color)
        // Bit 6-7: Number of floppy drives - 1
        // Bit 9-11: Number of serial ports
        // Bit 14-15: Number of parallel ports
        $equipmentFlags = 0x0021;  // Floppy present, 80x25 color, 1 floppy
        $equipmentFlags |= (2 << 9);   // 2 serial ports (COM1, COM2)
        $equipmentFlags |= (1 << 14);  // 1 parallel port (LPT1)
        $mem->writeBySize(0x410, $equipmentFlags, 16);

        // 0x412: Reserved (manufacturing test)
        $mem->writeBySize(0x412, 0x00, 8);

        // 0x413-0x414: Base memory size in KB (conventionally 640KB)
        $mem->writeBySize(0x413, self::CONVENTIONAL_MEMORY_KB, 16);

        // 0x415-0x416: Reserved
        $mem->writeBySize(0x415, 0x0000, 16);

        // ========================================
        // Keyboard (0x417-0x43D)
        // ========================================
        // 0x417: Keyboard shift flags 1
        // Bit 0: Right Shift pressed
        // Bit 1: Left Shift pressed
        // Bit 2: Ctrl pressed
        // Bit 3: Alt pressed
        // Bit 4: Scroll Lock active
        // Bit 5: Num Lock active
        // Bit 6: Caps Lock active
        // Bit 7: Insert mode active
        $mem->writeBySize(0x417, 0x00, 8);

        // 0x418: Keyboard shift flags 2
        $mem->writeBySize(0x418, 0x00, 8);

        // 0x419: Alt-numpad work area
        $mem->writeBySize(0x419, 0x00, 8);

        // 0x41A-0x41B: Keyboard buffer head pointer (offset from 0x400)
        $mem->writeBySize(0x41A, 0x001E, 16);

        // 0x41C-0x41D: Keyboard buffer tail pointer (offset from 0x400)
        $mem->writeBySize(0x41C, 0x001E, 16);

        // 0x41E-0x43D: Keyboard buffer (16 words = 32 bytes)
        for ($i = 0; $i < 32; $i++) {
            $mem->writeBySize(0x41E + $i, 0x00, 8);
        }

        // ========================================
        // Floppy Disk (0x43E-0x448)
        // ========================================
        // 0x43E: Floppy disk motor status
        $mem->writeBySize(0x43E, 0x00, 8);

        // 0x43F: Floppy disk motor timeout counter
        $mem->writeBySize(0x43F, 0x00, 8);

        // 0x440: Floppy disk status (last operation result)
        $mem->writeBySize(0x440, 0x00, 8);

        // 0x441-0x447: Floppy disk controller status bytes
        for ($i = 0; $i < 7; $i++) {
            $mem->writeBySize(0x441 + $i, 0x00, 8);
        }

        // 0x448: Current floppy disk drive
        $mem->writeBySize(0x448, 0x00, 8);

        // ========================================
        // Video (0x449-0x466)
        // ========================================
        // 0x449: Current video mode
        $mem->writeBySize(0x449, 0x03, 8);

        // 0x44A-0x44B: Number of screen columns
        $mem->writeBySize(0x44A, $cols, 16);

        // 0x44C-0x44D: Size of video regen buffer in bytes
        $mem->writeBySize(0x44C, $cols * $rows * 2, 16);

        // 0x44E-0x44F: Offset of current video page in video regen buffer
        $mem->writeBySize(0x44E, 0x0000, 16);

        // 0x450-0x45F: Cursor position for pages 0-7 (row, col pairs)
        for ($i = 0; $i < 16; $i++) {
            $mem->writeBySize(0x450 + $i, 0x00, 8);
        }

        // 0x460-0x461: Cursor shape (start/end scan lines)
        // For VGA: start=6, end=7
        $mem->writeBySize(0x460, 0x0607, 16);

        // 0x462: Current video page number
        $mem->writeBySize(0x462, 0x00, 8);

        // 0x463-0x464: Base I/O port for video (0x3D4 for color, 0x3B4 for mono)
        $mem->writeBySize(0x463, 0x03D4, 16);

        // 0x465: Current mode select register (3x8 port)
        $mem->writeBySize(0x465, 0x29, 8);

        // 0x466: Current palette register (3x9 port)
        $mem->writeBySize(0x466, 0x30, 8);

        // ========================================
        // POST & Misc (0x467-0x46B)
        // ========================================
        // 0x467-0x46B: POST reset address (for warm boot)
        $mem->writeBySize(0x467, 0x00000000, 32);
        $mem->writeBySize(0x46B, 0x00, 8);

        // ========================================
        // Timer (0x46C-0x470)
        // ========================================
        // 0x46C-0x46F: Timer tick counter (DWORD, ~18.2 Hz)
        $mem->writeBySize(0x46C, 0x00000000, 32);

        // 0x470: Timer overflow flag (24 hours elapsed)
        $mem->writeBySize(0x470, 0x00, 8);

        // ========================================
        // BIOS Break & Misc (0x471-0x474)
        // ========================================
        // 0x471: BIOS break flag (Ctrl+Break pressed)
        $mem->writeBySize(0x471, 0x00, 8);

        // 0x472-0x473: Soft reset flag (0x1234 = warm boot)
        $mem->writeBySize(0x472, 0x0000, 16);

        // 0x474: Reserved
        $mem->writeBySize(0x474, 0x00, 8);

        // ========================================
        // Hard Disk (0x475-0x477)
        // ========================================
        // 0x475: Number of hard drives
        $mem->writeBySize(0x475, 0x01, 8);

        // 0x476: Hard disk control byte
        $mem->writeBySize(0x476, 0x00, 8);

        // 0x477: Hard disk port offset
        $mem->writeBySize(0x477, 0x00, 8);

        // ========================================
        // LPT Timeout & COM Timeout (0x478-0x47F)
        // ========================================
        // 0x478-0x47B: LPT timeout values (4 bytes)
        $mem->writeBySize(0x478, 0x14, 8);  // LPT1 timeout
        $mem->writeBySize(0x479, 0x14, 8);  // LPT2 timeout
        $mem->writeBySize(0x47A, 0x14, 8);  // LPT3 timeout
        $mem->writeBySize(0x47B, 0x14, 8);  // LPT4 timeout

        // 0x47C-0x47F: COM timeout values (4 bytes)
        $mem->writeBySize(0x47C, 0x01, 8);  // COM1 timeout
        $mem->writeBySize(0x47D, 0x01, 8);  // COM2 timeout
        $mem->writeBySize(0x47E, 0x01, 8);  // COM3 timeout
        $mem->writeBySize(0x47F, 0x01, 8);  // COM4 timeout

        // ========================================
        // Keyboard Buffer Extended (0x480-0x483)
        // ========================================
        // 0x480-0x481: Keyboard buffer start offset (from segment 0x40)
        $mem->writeBySize(0x480, 0x001E, 16);

        // 0x482-0x483: Keyboard buffer end offset + 1
        $mem->writeBySize(0x482, 0x003E, 16);

        // ========================================
        // EGA/VGA (0x484-0x48A)
        // ========================================
        // 0x484: Number of rows - 1 (EGA/VGA)
        $mem->writeBySize(0x484, $rows - 1, 8);

        // 0x485-0x486: Character height in scan lines (EGA/VGA)
        $mem->writeBySize(0x485, 16, 16);

        // 0x487: EGA/VGA mode set options
        // Bit 0: Cursor emulation enabled
        // Bit 3: Video subsystem inactive
        // Bit 5-6: Memory size (11 = 256KB)
        $mem->writeBySize(0x487, 0x60, 8);

        // 0x488: EGA/VGA feature switches
        // Bit 0-3: Feature bits
        $mem->writeBySize(0x488, 0x09, 8);

        // 0x489: VGA mode options / display data area
        // Bit 0: VGA active
        // Bit 4: 400 scan line mode
        $mem->writeBySize(0x489, 0x11, 8);

        // 0x48A: VGA display combination code index
        $mem->writeBySize(0x48A, 0x00, 8);

        // ========================================
        // Media Control (0x48B)
        // ========================================
        // 0x48B: Last diskette data rate selected
        $mem->writeBySize(0x48B, 0x00, 8);

        // ========================================
        // Hard Disk Status (0x48C-0x48F)
        // ========================================
        // 0x48C: Hard disk status register
        $mem->writeBySize(0x48C, 0x00, 8);

        // 0x48D: Hard disk error register
        $mem->writeBySize(0x48D, 0x00, 8);

        // 0x48E: Hard disk task complete flag
        $mem->writeBySize(0x48E, 0x00, 8);

        // 0x48F: Reserved
        $mem->writeBySize(0x48F, 0x00, 8);

        // ========================================
        // Floppy Drive Info (0x490-0x491)
        // ========================================
        // 0x490: Floppy drive 0 media state
        $mem->writeBySize(0x490, 0x00, 8);

        // 0x491: Floppy drive 1 media state
        $mem->writeBySize(0x491, 0x00, 8);

        // ========================================
        // Floppy Drive State (0x492-0x495)
        // ========================================
        // 0x492: Floppy drive 0 start state
        $mem->writeBySize(0x492, 0x00, 8);

        // 0x493: Floppy drive 1 start state
        $mem->writeBySize(0x493, 0x00, 8);

        // 0x494: Floppy drive 0 current cylinder
        $mem->writeBySize(0x494, 0x00, 8);

        // 0x495: Floppy drive 1 current cylinder
        $mem->writeBySize(0x495, 0x00, 8);

        // ========================================
        // Keyboard Status (0x496-0x497)
        // ========================================
        // 0x496: Keyboard status flags 3
        $mem->writeBySize(0x496, 0x10, 8);  // Enhanced keyboard installed

        // 0x497: Keyboard status flags 4 (LED status)
        $mem->writeBySize(0x497, 0x00, 8);

        // ========================================
        // User Wait/RTC (0x498-0x49F)
        // ========================================
        // 0x498-0x49B: User wait flag pointer (DWORD)
        $mem->writeBySize(0x498, 0x00000000, 32);

        // 0x49C-0x49F: User wait count (DWORD, microseconds)
        $mem->writeBySize(0x49C, 0x00000000, 32);

        // ========================================
        // Wait Active Flag (0x4A0)
        // ========================================
        // 0x4A0: Wait active flag
        $mem->writeBySize(0x4A0, 0x00, 8);

        // ========================================
        // Network Adapter (0x4A1-0x4A7)
        // ========================================
        // 0x4A1-0x4A7: Reserved for network adapters
        for ($i = 0; $i < 7; $i++) {
            $mem->writeBySize(0x4A1 + $i, 0x00, 8);
        }

        // ========================================
        // EGA/VGA Palette (0x4A8-0x4AB)
        // ========================================
        // 0x4A8-0x4AB: Pointer to EGA parameter table (DWORD)
        $mem->writeBySize(0x4A8, 0x00000000, 32);

        // Fill remaining BDA area with zeros (0x4AC-0x4FF)
        for ($addr = 0x4AC; $addr <= self::BDA_END; $addr++) {
            $mem->writeBySize($addr, 0x00, 8);
        }

        $this->machine->option()->logger()->debug(
            sprintf('BIOS: Initialized BDA (0x%03X-0x%03X): cols=%d, rows=%d, memory=%dKB',
                self::BDA_START, self::BDA_END, $cols, $rows, self::CONVENTIONAL_MEMORY_KB)
        );
    }

    /**
     * Initialize Interrupt Vector Table (IVT) at 0x000-0x3FF.
     * Each vector is 4 bytes (segment:offset).
     */
    protected function initializeIVT(): void
    {
        $mem = $this->runtime()->memoryAccessor();

        // Default handler address: F000:FF53 (will contain IRET instruction)
        $defaultSegment = 0xF000;
        $defaultOffset = 0xFF53;

        // Initialize all 256 interrupt vectors to point to default handler
        for ($vector = 0; $vector < 256; $vector++) {
            $address = $vector * 4;
            // Store offset (low word)
            $mem->writeBySize($address, $defaultOffset, 16);
            // Store segment (high word)
            $mem->writeBySize($address + 2, $defaultSegment, 16);
        }

        $this->machine->option()->logger()->debug(
            sprintf('BIOS: Initialized IVT (0x000-0x3FF) with %d vectors pointing to F000:%04X', 256, $defaultOffset)
        );
    }

    /**
     * Initialize ROM area with BIOS identification and default interrupt handlers.
     */
    protected function initializeROM(): void
    {
        $mem = $this->runtime()->memoryAccessor();

        // Place default IRET handler at F000:FF53 (physical address 0xFFF53)
        // IRET opcode = 0xCF
        $iretAddress = (0xF000 << 4) + 0xFF53;  // 0xFFF53
        $mem->writeBySize($iretAddress, 0xCF, 8);

        // BIOS identification string at F000:E000 (physical address 0xFE000)
        $biosId = "PHPMachineEmulator BIOS";
        $biosIdAddress = (0xF000 << 4) + 0xE000;  // 0xFE000
        for ($i = 0; $i < strlen($biosId); $i++) {
            $mem->writeBySize($biosIdAddress + $i, ord($biosId[$i]), 8);
        }

        // BIOS date at F000:FFF5 (physical address 0xFFFF5) - format: MM/DD/YY
        $date = date('m/d/y');
        $dateAddress = (0xF000 << 4) + 0xFFF5;  // 0xFFFF5
        for ($i = 0; $i < strlen($date); $i++) {
            $mem->writeBySize($dateAddress + $i, ord($date[$i]), 8);
        }

        // System model ID at F000:FFFE (physical address 0xFFFFE)
        // 0xFC = AT compatible
        $mem->writeBySize(0xFFFFE, 0xFC, 8);

        $this->machine->option()->logger()->debug(
            sprintf('BIOS: Initialized ROM area with IRET at 0x%05X, ID at 0x%05X', $iretAddress, $biosIdAddress)
        );
    }

    protected function verifyBIOSSignature(): void
    {
        $bootStream = $this->machine->logicBoard()->media()->primary()->stream();
        $proxy = $bootStream->proxy();
        try {
            $proxy->setOffset(510);
        } catch (StreamReaderException) {
            throw new BIOSInvalidException('The disk is invalid. Failed to change offsets');
        }

        $low = $proxy->byte();
        $high = $proxy->byte();

        if ($high !== 0xAA || $low !== 0x55) {
            throw new BIOSInvalidException('The BIOS signature is invalid');
        }
    }
}
