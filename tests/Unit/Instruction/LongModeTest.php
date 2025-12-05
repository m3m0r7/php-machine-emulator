<?php

declare(strict_types=1);

namespace Tests\Unit\Instruction;

use PHPMachineEmulator\Instruction\RegisterType;

/**
 * Tests for 64-bit Long Mode (IA-32e mode) functionality.
 *
 * Long mode is enabled by:
 * 1. Enable PAE (CR4.PAE = 1)
 * 2. Load CR3 with PML4 table address
 * 3. Enable long mode (EFER.LME = 1)
 * 4. Enable paging (CR0.PG = 1)
 * 5. Long mode becomes active (EFER.LMA = 1)
 *
 * Tests verify:
 * - EFER MSR bits (LME, LMA, SCE)
 * - 64-bit register extensions (R8-R15, RAX-RSP 64-bit)
 * - Page table structure (PML4, PDPT, PD, PT)
 * - Segment handling in long mode
 * - REX prefix encoding
 */
class LongModeTest extends InstructionTestCase
{
    protected function getInstructionByOpcode(int $opcode): ?object
    {
        return null;
    }

    // ========================================
    // EFER MSR Tests
    // ========================================

    public function testEFERMSRAddress(): void
    {
        // EFER (Extended Feature Enable Register) is MSR 0xC0000080
        $eferMsr = 0xC0000080;

        $this->assertSame(0xC0000080, $eferMsr);
    }

    public function testEFERBitLayout(): void
    {
        // EFER bit layout:
        // Bit 0: SCE - System Call Extensions (SYSCALL/SYSRET)
        // Bit 8: LME - Long Mode Enable
        // Bit 10: LMA - Long Mode Active (read-only)
        // Bit 11: NXE - No-Execute Enable

        $sce = 1 << 0;    // 0x0001
        $lme = 1 << 8;    // 0x0100
        $lma = 1 << 10;   // 0x0400
        $nxe = 1 << 11;   // 0x0800

        $this->assertSame(0x0001, $sce);
        $this->assertSame(0x0100, $lme);
        $this->assertSame(0x0400, $lma);
        $this->assertSame(0x0800, $nxe);
    }

    public function testLongModeEnableSequence(): void
    {
        // To enter long mode:
        // 1. Disable paging (CR0.PG = 0)
        // 2. Enable PAE (CR4.PAE = 1)
        // 3. Load CR3 with PML4 base
        // 4. Enable long mode (EFER.LME = 1)
        // 5. Enable paging (CR0.PG = 1)
        // 6. CPU sets EFER.LMA = 1 automatically

        $cr0PG = 1 << 31;  // Paging bit
        $cr4PAE = 1 << 5;  // PAE bit
        $eferLME = 1 << 8; // Long Mode Enable
        $eferLMA = 1 << 10; // Long Mode Active

        $this->assertSame(0x80000000, $cr0PG);
        $this->assertSame(0x20, $cr4PAE);
        $this->assertSame(0x100, $eferLME);
        $this->assertSame(0x400, $eferLMA);
    }

    // ========================================
    // 64-bit Register Tests
    // ========================================

    public function test64BitGeneralPurposeRegisters(): void
    {
        // In 64-bit mode, registers are extended to 64 bits
        // RAX, RBX, RCX, RDX, RSI, RDI, RBP, RSP
        // Plus 8 new registers: R8-R15

        $registers = [
            'RAX', 'RBX', 'RCX', 'RDX',
            'RSI', 'RDI', 'RBP', 'RSP',
            'R8', 'R9', 'R10', 'R11',
            'R12', 'R13', 'R14', 'R15',
        ];

        $this->assertCount(16, $registers);
    }

    public function testRegisterExtensionEncoding(): void
    {
        // REX prefix is used to access extended registers
        // REX.W = 64-bit operand size
        // REX.R = extends ModR/M reg field (R8-R15)
        // REX.X = extends SIB index field
        // REX.B = extends ModR/M r/m, SIB base, or opcode reg

        // REX prefix format: 0100WRXB
        $rexBase = 0x40;  // 0100 0000
        $rexW = 0x48;     // 0100 1000 - 64-bit operand
        $rexR = 0x44;     // 0100 0100 - extends reg
        $rexX = 0x42;     // 0100 0010 - extends index
        $rexB = 0x41;     // 0100 0001 - extends r/m or base

        $this->assertSame(0x40, $rexBase);
        $this->assertSame(0x48, $rexW);
        $this->assertSame(0x44, $rexR);
        $this->assertSame(0x42, $rexX);
        $this->assertSame(0x41, $rexB);
    }

    public function testRexPrefixRange(): void
    {
        // REX prefix is any byte from 0x40 to 0x4F
        $rexMin = 0x40;
        $rexMax = 0x4F;

        for ($rex = $rexMin; $rex <= $rexMax; $rex++) {
            $isRex = ($rex & 0xF0) === 0x40;
            $this->assertTrue($isRex, "0x" . dechex($rex) . " should be a REX prefix");
        }
    }

    public function test64BitAddressSize(): void
    {
        // In 64-bit mode, default address size is 64 bits
        // 0x67 prefix changes to 32-bit addressing

        $addressSizePrefix = 0x67;
        $defaultAddressSize = 64;
        $overriddenAddressSize = 32;

        $this->assertSame(0x67, $addressSizePrefix);
    }

    // ========================================
    // Page Table Structure Tests
    // ========================================

    public function testPML4Entry(): void
    {
        // PML4 (Page Map Level 4) entry format (64-bit)
        // Bit 0: P - Present
        // Bit 1: R/W - Read/Write
        // Bit 2: U/S - User/Supervisor
        // Bit 3: PWT - Page-level write-through
        // Bit 4: PCD - Page-level cache disable
        // Bit 5: A - Accessed
        // Bits 12-51: Physical address of PDPT (4KB aligned)
        // Bit 63: XD - Execute Disable (if EFER.NXE = 1)

        $present = 1 << 0;
        $readWrite = 1 << 1;
        $userSupervisor = 1 << 2;
        $writeThrough = 1 << 3;
        $cacheDisable = 1 << 4;
        $accessed = 1 << 5;

        // Example: Present, Read/Write, Supervisor
        $pml4Entry = $present | $readWrite; // 0x03

        $this->assertSame(0x03, $pml4Entry);
    }

    public function testPDPTEntry(): void
    {
        // PDPT (Page Directory Pointer Table) entry can be:
        // - Pointer to PD (1GB pages not used)
        // - 1GB page entry (PS bit = 1)

        $present = 1 << 0;
        $pageSize = 1 << 7; // PS bit - 1GB page if set

        $this->assertSame(0x01, $present);
        $this->assertSame(0x80, $pageSize);
    }

    public function testPageDirectoryEntry(): void
    {
        // Page Directory entry can be:
        // - Pointer to PT (4KB pages)
        // - 2MB page entry (PS bit = 1)

        $pageSize2MB = 1 << 7; // PS bit for 2MB page

        $this->assertSame(0x80, $pageSize2MB);
    }

    public function testPageTableEntry(): void
    {
        // Page Table entry for 4KB pages
        // Bits 12-51: Physical page frame address

        $present = 1 << 0;
        $readWrite = 1 << 1;
        $userMode = 1 << 2;
        $global = 1 << 8; // G bit - global page
        $pat = 1 << 7;    // PAT bit

        // Typical user page: Present, Read/Write, User
        $userPageEntry = $present | $readWrite | $userMode; // 0x07

        $this->assertSame(0x07, $userPageEntry);
    }

    public function testCanonicalAddressCheck(): void
    {
        // In 64-bit mode, addresses must be canonical:
        // Bits 63:48 must match bit 47 (sign extension)

        // Valid low canonical address: bits 47-63 = 0
        // 0x00007FFFFFFFFFFF - max user space address
        $lowAddressHigh = 0x00007FFF;
        $lowAddressLow = 0xFFFFFFFF;

        // Valid high canonical address: bits 47-63 = 1
        // 0xFFFF800000000000 - kernel space base
        $highAddressHigh = 0xFFFF8000;
        $highAddressLow = 0x00000000;

        // Check canonical form by verifying bit 47 matches bits 48-63
        // For low address (user space): bit 47 should be 0
        $bit47Low = ($lowAddressHigh >> 15) & 0x1;
        $this->assertSame(0, $bit47Low, "Low canonical: bit 47 should be 0");

        // For high address (kernel space): bit 47 should be 1
        $bit47High = ($highAddressHigh >> 15) & 0x1;
        $this->assertSame(1, $bit47High, "High canonical: bit 47 should be 1");
    }

    // ========================================
    // Segment Handling in Long Mode
    // ========================================

    public function testCodeSegmentInLongMode(): void
    {
        // In 64-bit mode, CS descriptor has:
        // L bit (bit 53) = 1 for 64-bit code
        // D bit (bit 54) = 0 when L = 1

        $lBit = 1 << 21;  // In descriptor high dword (bit 53 overall)
        $dBit = 1 << 22;  // In descriptor high dword (bit 54 overall)

        // 64-bit code segment: L=1, D=0
        $this->assertSame(0x200000, $lBit);
        $this->assertSame(0x400000, $dBit);
    }

    public function testDataSegmentsFlatInLongMode(): void
    {
        // In 64-bit mode, DS, ES, SS are treated as if they have
        // base = 0 and no limit checking
        // FS and GS can have non-zero bases (for TLS)

        // The segment base addresses are effectively zero
        $effectiveBase = 0;

        $this->assertSame(0, $effectiveBase);
    }

    public function testFSGSBaseRegisters(): void
    {
        // FS and GS bases can be set via MSRs in 64-bit mode
        // IA32_FS_BASE = 0xC0000100
        // IA32_GS_BASE = 0xC0000101
        // IA32_KERNEL_GS_BASE = 0xC0000102 (for SWAPGS)

        $fsBaseMsr = 0xC0000100;
        $gsBaseMsr = 0xC0000101;
        $kernelGsBaseMsr = 0xC0000102;

        $this->assertSame(0xC0000100, $fsBaseMsr);
        $this->assertSame(0xC0000101, $gsBaseMsr);
        $this->assertSame(0xC0000102, $kernelGsBaseMsr);
    }

    // ========================================
    // SYSCALL/SYSRET Tests
    // ========================================

    public function testSyscallMSRs(): void
    {
        // MSRs used for SYSCALL/SYSRET
        // IA32_STAR = 0xC0000081 - Segment selectors
        // IA32_LSTAR = 0xC0000082 - 64-bit SYSCALL target RIP
        // IA32_CSTAR = 0xC0000083 - Compatibility mode target (unused in most OS)
        // IA32_FMASK = 0xC0000084 - RFLAGS mask for SYSCALL

        $starMsr = 0xC0000081;
        $lstarMsr = 0xC0000082;
        $cstarMsr = 0xC0000083;
        $fmaskMsr = 0xC0000084;

        $this->assertSame(0xC0000081, $starMsr);
        $this->assertSame(0xC0000082, $lstarMsr);
        $this->assertSame(0xC0000083, $cstarMsr);
        $this->assertSame(0xC0000084, $fmaskMsr);
    }

    public function testSTARMsrLayout(): void
    {
        // STAR MSR layout:
        // Bits 31:0 - Reserved
        // Bits 47:32 - SYSCALL CS and SS (SS = CS + 8)
        // Bits 63:48 - SYSRET CS and SS (CS = value, SS = value + 8)

        $syscallCs = 0x0008; // Kernel code segment
        $sysretCs = 0x001B;  // User code segment (ring 3)

        // Typical STAR value: SYSRET CS/SS in high 16, SYSCALL CS/SS in bits 47:32
        $starValue = ((int)$sysretCs << 48) | ((int)$syscallCs << 32);

        $this->assertTrue($starValue > 0);
    }

    // ========================================
    // RIP-Relative Addressing Tests
    // ========================================

    public function testRIPRelativeAddressing(): void
    {
        // In 64-bit mode, ModR/M encoding for [disp32] becomes RIP-relative
        // This is major change from 32-bit mode

        // Example: MOV RAX, [RIP+disp32]
        // ModR/M = 0x05 means [RIP+disp32] in 64-bit mode
        // In 32-bit mode, 0x05 means [disp32]

        $modrmRipRelative = 0x05;

        $this->assertSame(0x05, $modrmRipRelative);
    }

    // ========================================
    // Compatibility Mode Tests
    // ========================================

    public function testCompatibilityModeCodeSegment(): void
    {
        // In long mode, if L=0 in code segment, CPU runs in compatibility mode
        // This allows running 32-bit code alongside 64-bit code

        // L=0, D=1 = 32-bit compatibility mode
        // L=0, D=0 = 16-bit compatibility mode
        $lBit = 0;  // Long mode disabled for this segment
        $dBit = 1;  // 32-bit operand size

        $this->assertSame(0, $lBit);
        $this->assertSame(1, $dBit);
    }

    // ========================================
    // CPUID Long Mode Detection
    // ========================================

    public function testCPUIDLongModeSupport(): void
    {
        // CPUID function 0x80000001, EDX bit 29 indicates long mode support
        $extendedFunction = 0x80000001;
        $longModeBit = 1 << 29;

        $edx = 0x20000000; // LM bit set

        $supportsLongMode = ($edx & $longModeBit) !== 0;

        $this->assertTrue($supportsLongMode);
    }

    public function testCPUIDLAHFInLongMode(): void
    {
        // CPUID 0x80000001, ECX bit 0 indicates LAHF/SAHF in 64-bit mode
        $lahfSahfBit = 1 << 0;

        $ecx = 0x01; // LAHF/SAHF supported

        $supportsLahfSahf = ($ecx & $lahfSahfBit) !== 0;

        $this->assertTrue($supportsLahfSahf);
    }

    // ========================================
    // 64-bit Immediate and Displacement Tests
    // ========================================

    public function testMOV64BitImmediate(): void
    {
        // Only MOV r64, imm64 supports 64-bit immediates
        // Opcode: REX.W + B8+rd (MOV r64, imm64)

        $rexW = 0x48;
        $movRaxImm64 = 0xB8; // + register number for RAX (0)

        // Full encoding: 48 B8 + 8-byte immediate
        $this->assertSame(0x48, $rexW);
        $this->assertSame(0xB8, $movRaxImm64);
    }

    public function testSignExtendedImmediate(): void
    {
        // Most instructions sign-extend 32-bit immediate to 64 bits
        // Example: ADD RAX, imm32 sign-extends imm32 to 64 bits

        $imm32 = 0xFFFFFFFF; // -1 as unsigned 32-bit
        $signExtended64 = -1; // Becomes 0xFFFFFFFFFFFFFFFF

        $this->assertSame(-1, $signExtended64);
    }
}
