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

        // 2. Initialize CPU state (flags, descriptor tables, A20, etc.)
        $this->initializeCPUState();

        // 3. Initialize services (e.g., VideoMemoryService)
        $this->initializeServices();

        // 4. Initialize IVT first (to handle exceptions safely)
        $this->initializeIVT();


        // 6. Initialize PIC (Programmable Interrupt Controller)
        $this->initializePIC();

        // 7. Initialize ROM area (BIOS identification, default handlers)
        $this->initializeROM();

        // 7. Initialize BDA
        $this->initializeBDA();

        // 8. Initialize segment registers (DS, ES, SS, etc.)
        $this->initializeSegmentRegisters();

        // 9. Initialize boot segment (CS register) - should be last before boot
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
        // Report minimal hardware during early boot.
        // DOS uses these counts to probe INT 14h/17h; we currently don't emulate UART/LPT,
        // so advertise none to avoid calling into uninitialized device vectors.
        $equipmentFlags = 0x0021;  // Floppy present, 80x25 color, 1 floppy, no serial/LPT
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

        // Initialize all 256 interrupt vectors to point to default handler.
        // Use physical writes here because low IVT addresses (0x0000-0x000D)
        // overlap with the internal register address space.
        for ($vector = 0; $vector < 256; $vector++) {
            $address = $vector * 4;
            // Store offset (low word)
            $mem->writePhysical16($address, $defaultOffset);
            // Store segment (high word)
            $mem->writePhysical16($address + 2, $defaultSegment);
        }

        $this->machine->option()->logger()->debug(
            sprintf('BIOS: Initialized IVT (0x000-0x3FF) with %d vectors pointing to F000:%04X', 256, $defaultOffset)
        );
    }

    /**
     * Initialize PIC (Programmable Interrupt Controller).
     *
     * Sets up the 8259A PIC with standard PC/AT configuration:
     * - Master PIC: IRQ0-7 mapped to INT 8-F
     * - Slave PIC: IRQ8-15 mapped to INT 70-77
     * - IRQ0 (timer) enabled, others masked
     */
    protected function initializePIC(): void
    {
        $picState = $this->runtime()->context()->cpu()->picState();

        // Enable IRQ0 (timer), mask others on master PIC
        // Bit 0 = 0 means IRQ0 is enabled
        $picState->maskMaster(0xFE);

        // Mask all IRQs on slave PIC
        $picState->maskSlave(0xFF);

        $this->machine->option()->logger()->debug(
            'BIOS: Initialized PIC - IRQ0 (timer) enabled, others masked'
        );
    }

    /**
     * Initialize CPU state (flags, descriptor tables, control registers, A20 line).
     * Based on x86 CPU reset state from Intel SDM and QEMU's x86_cpu_reset_hold().
     */
    protected function initializeCPUState(): void
    {
        $runtime = $this->runtime();
        $mem = $runtime->memoryAccessor();
        $cpuContext = $runtime->context()->cpu();

        // ========================================
        // CPU Mode: Real Mode (16-bit)
        // ========================================
        $cpuContext->setProtectedMode(false);
        $cpuContext->setDefaultOperandSize(16);
        $cpuContext->setDefaultAddressSize(16);

        // ========================================
        // Control Registers
        // ========================================
        // CR0: PE=0 (real mode), others as per reset
        // Bit 4 (ET): Extension Type - 1 for 387 coprocessor
        // Keep existing MP + NE bits (0x22) and add ET
        $cr0 = 0x22 | 0x10;  // MP + NE + ET
        $mem->writeControlRegister(0, $cr0);

        // CR2, CR3, CR4: All zero on reset
        $mem->writeControlRegister(2, 0);
        $mem->writeControlRegister(3, 0);
        $mem->writeControlRegister(4, 0);

        // ========================================
        // EFLAGS: Reset to 0x00000002
        // Only bit 1 is reserved and always set to 1
        // ========================================
        // Note: Flags are stored individually in MemoryAccessor
        // Clear all flags except reserved bit 1
        $mem->setCarryFlag(false);
        $mem->setZeroFlag(false);
        $mem->setSignFlag(false);
        $mem->setOverflowFlag(false);
        $mem->setParityFlag(false);
        $mem->setAuxiliaryCarryFlag(false);
        $mem->setDirectionFlag(false);
        $mem->setInterruptFlag(false);  // IF=0 on reset (interrupts disabled)

        // ========================================
        // Descriptor Table Registers
        // On reset: base=0, limit=0xFFFF
        // ========================================
        $cpuContext->setGdtr(0x00000000, 0xFFFF);
        $cpuContext->setIdtr(0x00000000, 0xFFFF);  // IVT at 0x0000:0x0000 with limit 0x3FF in real mode

        // Task Register: selector=0, base=0, limit=0xFFFF
        $cpuContext->setTaskRegister(0, 0x00000000, 0xFFFF);

        // LDTR: selector=0, base=0, limit=0xFFFF
        $cpuContext->setLdtr(0, 0x00000000, 0xFFFF);

        // ========================================
        // Privilege Level
        // ========================================
        $cpuContext->setCpl(0);   // Ring 0 on reset
        $cpuContext->setIopl(0);  // IOPL=0 on reset
        $cpuContext->setNt(false); // NT flag clear

        // ========================================
        // A20 Line
        // Enable A20 for full memory access (QEMU enables by default)
        // ========================================
        $cpuContext->enableA20(true);

        // ========================================
        // Paging: Disabled on reset
        // ========================================
        $cpuContext->setPagingEnabled(false);

        // ========================================
        // EFER (Extended Feature Enable Register): 0 on reset
        // ========================================
        $mem->writeEfer(0);

        $this->machine->option()->logger()->debug(
            sprintf('BIOS: Initialized CPU state - Real Mode 16-bit, CR0=0x%08X, A20=enabled, IF=0', $cr0)
        );
    }

    /**
     * Initialize segment registers (DS, ES, SS, FS, GS) and stack pointer.
     * On x86 reset: selector=0, base=0, limit=0xFFFF for all data segments.
     */
    protected function initializeSegmentRegisters(): void
    {
        $mem = $this->runtime()->memoryAccessor();

        // ========================================
        // Data Segment Registers: All 0 on reset
        // In real mode, segment base = segment << 4
        // ========================================
        $mem->write16Bit(RegisterType::DS, 0x0000);
        $mem->write16Bit(RegisterType::ES, 0x0000);
        $mem->write16Bit(RegisterType::SS, 0x0000);
        $mem->write16Bit(RegisterType::FS, 0x0000);
        $mem->write16Bit(RegisterType::GS, 0x0000);

        // ========================================
        // Stack Pointer
        // On reset, SP is undefined but typically set by BIOS
        // Set SP to top of conventional memory segment (0x0000:0xFFFE)
        // This gives 64KB stack space in real mode
        // ========================================
        $mem->write16Bit(RegisterType::ESP, 0xFFFE);

        // ========================================
        // General Purpose Registers
        // On reset, EAX contains CPU identification info
        // Other registers are undefined but we zero them
        // ========================================
        $mem->writeBySize(RegisterType::EAX, 0x00000000, 32);
        $mem->writeBySize(RegisterType::EBX, 0x00000000, 32);
        $mem->writeBySize(RegisterType::ECX, 0x00000000, 32);
        $mem->writeBySize(RegisterType::EDX, 0x00000000, 32);
        $mem->writeBySize(RegisterType::ESI, 0x00000000, 32);
        $mem->writeBySize(RegisterType::EDI, 0x00000000, 32);
        $mem->writeBySize(RegisterType::EBP, 0x00000000, 32);

        $this->machine->option()->logger()->debug(
            'BIOS: Initialized segment registers DS=ES=SS=FS=GS=0, SP=0xFFFE'
        );
    }

    /**
     * Initialize ROM area with BIOS identification and default interrupt handlers.
     *
     * ISOLINUX and other bootloaders use RETF to call BIOS services indirectly.
     * They push a return address, set up registers (e.g., AH=42h for disk read),
     * then RETF to F000:xxxx. We use PHPBIOSCall (0F FF xx) to handle these directly.
     *
     * This also allows custom interrupt handlers to call "original" BIOS handlers
     * via jmp far [orig_intXX] without causing infinite loops through IVT.
     */
    protected function initializeROM(): void
    {
        $mem = $this->runtime()->memoryAccessor();

        // ========================================
        // BIOS Interrupt Trampolines using PHPBIOSCall
        // ========================================
        // Each trampoline is 4 bytes: 0F FF xx (PHPBIOSCall) + CF (IRET)
        // PHPBIOSCall directly invokes PHP BIOS handlers WITHOUT IVT lookup.
        // This prevents infinite loops when custom handlers call original BIOS.
        //
        // Layout at F000:FF00-F000:FF5F:
        //   FF00: PHPBIOSCall 10h + IRET (video)
        //   FF04: PHPBIOSCall 13h + IRET (disk) - CRITICAL for ISOLINUX
        //   FF08: PHPBIOSCall 15h + IRET (system)
        //   FF0C: PHPBIOSCall 16h + IRET (keyboard)
        //   ...
        //   FF53: Default IRET-only handler

        $trampolineBase = (0xF000 << 4) + 0xFF00;  // 0xFFF00

        // INT 10h - Video services (F000:FF00)
        $mem->writeBySize($trampolineBase + 0x00, 0x0F, 8);  // Two-byte opcode prefix
        $mem->writeBySize($trampolineBase + 0x01, 0xFF, 8);  // PHPBIOSCall
        $mem->writeBySize($trampolineBase + 0x02, 0x10, 8);  // 10h
        $mem->writeBySize($trampolineBase + 0x03, 0xCF, 8);  // IRET

        // INT 13h - Disk services (F000:FF04) - CRITICAL for ISOLINUX disk reads
        $mem->writeBySize($trampolineBase + 0x04, 0x0F, 8);  // Two-byte opcode prefix
        $mem->writeBySize($trampolineBase + 0x05, 0xFF, 8);  // PHPBIOSCall
        $mem->writeBySize($trampolineBase + 0x06, 0x13, 8);  // 13h
        $mem->writeBySize($trampolineBase + 0x07, 0xCF, 8);  // IRET

        // INT 15h - System services (F000:FF08)
        $mem->writeBySize($trampolineBase + 0x08, 0x0F, 8);  // Two-byte opcode prefix
        $mem->writeBySize($trampolineBase + 0x09, 0xFF, 8);  // PHPBIOSCall
        $mem->writeBySize($trampolineBase + 0x0A, 0x15, 8);  // 15h
        $mem->writeBySize($trampolineBase + 0x0B, 0xCF, 8);  // IRET

        // INT 16h - Keyboard services (F000:FF0C)
        $mem->writeBySize($trampolineBase + 0x0C, 0x0F, 8);  // Two-byte opcode prefix
        $mem->writeBySize($trampolineBase + 0x0D, 0xFF, 8);  // PHPBIOSCall
        $mem->writeBySize($trampolineBase + 0x0E, 0x16, 8);  // 16h
        $mem->writeBySize($trampolineBase + 0x0F, 0xCF, 8);  // IRET

        // INT 1Ah - Time of day (F000:FF10)
        $mem->writeBySize($trampolineBase + 0x10, 0x0F, 8);  // Two-byte opcode prefix
        $mem->writeBySize($trampolineBase + 0x11, 0xFF, 8);  // PHPBIOSCall
        $mem->writeBySize($trampolineBase + 0x12, 0x1A, 8);  // 1Ah
        $mem->writeBySize($trampolineBase + 0x13, 0xCF, 8);  // IRET

        // INT 08h - Timer tick (F000:FF14)
        $mem->writeBySize($trampolineBase + 0x14, 0x0F, 8);  // Two-byte opcode prefix
        $mem->writeBySize($trampolineBase + 0x15, 0xFF, 8);  // PHPBIOSCall
        $mem->writeBySize($trampolineBase + 0x16, 0x08, 8);  // 08h
        $mem->writeBySize($trampolineBase + 0x17, 0xCF, 8);  // IRET

        // INT 12h - Memory size (F000:FF18)
        $mem->writeBySize($trampolineBase + 0x18, 0x0F, 8);  // Two-byte opcode prefix
        $mem->writeBySize($trampolineBase + 0x19, 0xFF, 8);  // PHPBIOSCall
        $mem->writeBySize($trampolineBase + 0x1A, 0x12, 8);  // 12h
        $mem->writeBySize($trampolineBase + 0x1B, 0xCF, 8);  // IRET

        // Default IRET-only handler at F000:FF53 (physical 0xFFF53)
        // This is for interrupts that don't need special handling
        $iretAddress = (0xF000 << 4) + 0xFF53;  // 0xFFF53
        $mem->writeBySize($iretAddress, 0xCF, 8);

        // ========================================
        // Update IVT to point to trampolines instead of IRET-only handler
        // ========================================
        // INT 10h -> F000:FF00
        $mem->writeBySize(0x10 * 4, 0xFF00, 16);      // offset
        $mem->writeBySize(0x10 * 4 + 2, 0xF000, 16);  // segment

        // INT 13h -> F000:FF04 (CRITICAL for ISOLINUX)
        $mem->writeBySize(0x13 * 4, 0xFF04, 16);      // offset
        $mem->writeBySize(0x13 * 4 + 2, 0xF000, 16);  // segment

        // INT 15h -> F000:FF08
        $mem->writeBySize(0x15 * 4, 0xFF08, 16);      // offset
        $mem->writeBySize(0x15 * 4 + 2, 0xF000, 16);  // segment

        // INT 16h -> F000:FF0C
        $mem->writeBySize(0x16 * 4, 0xFF0C, 16);      // offset
        $mem->writeBySize(0x16 * 4 + 2, 0xF000, 16);  // segment

        // INT 1Ah -> F000:FF10
        $mem->writeBySize(0x1A * 4, 0xFF10, 16);      // offset
        $mem->writeBySize(0x1A * 4 + 2, 0xF000, 16);  // segment

        // INT 08h -> F000:FF14
        $mem->writeBySize(0x08 * 4, 0xFF14, 16);      // offset
        $mem->writeBySize(0x08 * 4 + 2, 0xF000, 16);  // segment

        // INT 12h -> F000:FF18
        $mem->writeBySize(0x12 * 4, 0xFF18, 16);      // offset
        $mem->writeBySize(0x12 * 4 + 2, 0xF000, 16);  // segment

        // ========================================
        // BIOS Identification
        // ========================================
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
            sprintf('BIOS: Initialized ROM with trampolines at 0x%05X, INT13h at F000:FF03, ID at 0x%05X',
                $trampolineBase, $biosIdAddress)
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
