//! High-performance memory accessor implementation for x86 emulation.
//!
//! This module provides a Rust implementation of MemoryAccessor that manages
//! CPU registers, flags, and memory access for x86 emulation.

use crate::memory_stream::MemoryStream;

/// Register addresses layout:
/// 0-7:   GPRs (EAX-EDI / RAX-RDI)
/// 8-13:  Segment registers (ES, CS, SS, DS, FS, GS)
/// 14-15: Reserved
/// 16-23: Extended GPRs (R8-R15)
/// 24:    RIP
/// 25:    EDI_ON_MEMORY (special)
const MAX_REGISTER_ADDRESS: usize = 26;

/// MemoryAccessor structure for managing CPU registers and flags.
#[repr(C)]
pub struct MemoryAccessor {
    /// Register storage (64-bit values for GPRs, 16-bit for segment registers)
    registers: [i64; MAX_REGISTER_ADDRESS],
    /// Which registers are allocated
    registers_allocated: [bool; MAX_REGISTER_ADDRESS],

    /// CPU Flags
    zero_flag: bool,
    sign_flag: bool,
    overflow_flag: bool,
    carry_flag: bool,
    parity_flag: bool,
    auxiliary_carry_flag: bool,
    direction_flag: bool,
    interrupt_flag: bool,
    instruction_fetch: bool,

    /// Extended Feature Enable Register (EFER MSR)
    efer: u64,

    /// Control registers (CR0-CR4)
    control_registers: [u32; 5],

    /// Pointer to the memory stream (owned by PHP, just referenced here)
    memory: *mut MemoryStream,
}

impl MemoryAccessor {
    /// Create a new MemoryAccessor.
    pub fn new(memory: *mut MemoryStream) -> Self {
        let accessor = MemoryAccessor {
            registers: [0; MAX_REGISTER_ADDRESS],
            registers_allocated: [false; MAX_REGISTER_ADDRESS],
            zero_flag: false,
            sign_flag: false,
            overflow_flag: false,
            carry_flag: false,
            parity_flag: false,
            auxiliary_carry_flag: false,
            direction_flag: false,
            interrupt_flag: false,
            instruction_fetch: false,
            efer: 0,
            control_registers: [0x22, 0, 0, 0, 0], // CR0: MP + NE set
            memory,
        };
        accessor
    }

    /// Check if address is a register address.
    #[inline(always)]
    fn is_register_address(address: usize) -> bool {
        (address <= 13) || (address >= 16 && address <= 25)
    }

    /// Check if address is a GPR address.
    #[inline(always)]
    fn is_gpr_address(address: usize) -> bool {
        (address <= 7) || (address >= 16 && address <= 24)
    }

    /// Allocate a register or memory range.
    pub fn allocate(&mut self, address: usize, size: usize, safe: bool) -> bool {
        if Self::is_register_address(address) {
            if safe && self.registers_allocated[address] {
                return false; // Already allocated
            }
            for i in 0..size {
                let addr = address + i;
                if addr < MAX_REGISTER_ADDRESS && Self::is_register_address(addr) {
                    self.registers_allocated[addr] = true;
                    self.registers[addr] = 0;
                }
            }
            return true;
        }
        // General memory is handled by MemoryStream
        true
    }

    /// Fetch a register value.
    #[inline(always)]
    pub fn fetch(&self, address: usize) -> i64 {
        if Self::is_register_address(address) && address < MAX_REGISTER_ADDRESS {
            self.registers[address]
        } else {
            // Read from memory
            unsafe {
                if !self.memory.is_null() {
                    (*self.memory).read_byte_at(address) as i64
                } else {
                    0
                }
            }
        }
    }

    /// Fetch a register value with size.
    #[inline(always)]
    pub fn fetch_by_size(&self, address: usize, size: u32) -> i64 {
        let value = self.fetch(address);
        match size {
            8 => value & 0xFF,
            16 => value & 0xFFFF,
            32 => value & 0xFFFFFFFF,
            64 => value,
            _ => value,
        }
    }

    /// Try to fetch a register value (returns -1 if not allocated).
    pub fn try_to_fetch(&self, address: usize) -> i64 {
        if Self::is_register_address(address) && address < MAX_REGISTER_ADDRESS {
            if !self.registers_allocated[address] {
                return -1; // Not allocated sentinel
            }
            self.registers[address]
        } else {
            unsafe {
                if !self.memory.is_null() {
                    (*self.memory).read_byte_at(address) as i64
                } else {
                    0
                }
            }
        }
    }

    /// Write a 16-bit value.
    #[inline(always)]
    pub fn write_16bit(&mut self, address: usize, value: i64) {
        self.write_by_size(address, value, 16);
    }

    /// Write a value by size.
    #[inline(always)]
    pub fn write_by_size(&mut self, address: usize, value: i64, size: u32) {
        if Self::is_register_address(address) && address < MAX_REGISTER_ADDRESS {
            if !self.registers_allocated[address] {
                self.registers_allocated[address] = true;
            }

            let is_gpr = Self::is_gpr_address(address);

            if is_gpr {
                let current = self.registers[address];
                let new_value = match size {
                    8 => (current & !0xFF) | (value & 0xFF),
                    16 => (current & !0xFFFF) | (value & 0xFFFF),
                    32 => value & 0xFFFFFFFF, // Zero-extend to 64-bit
                    _ => value,
                };
                self.registers[address] = new_value;
            } else {
                self.registers[address] = value;
            }
        } else {
            // Write to memory
            unsafe {
                if !self.memory.is_null() {
                    let bytes = (size / 8) as usize;
                    for i in 0..bytes {
                        (*self.memory).write_byte_at(address + i, ((value >> (i * 8)) & 0xFF) as u8);
                    }
                }
            }
        }
    }

    /// Write to high bit (bits 8-15).
    #[inline(always)]
    pub fn write_to_high_bit(&mut self, address: usize, value: i64) {
        if Self::is_register_address(address) && address < MAX_REGISTER_ADDRESS {
            if !self.registers_allocated[address] {
                self.registers_allocated[address] = true;
            }
            let current = self.registers[address];
            let new_value = (current & !0xFF00) | ((value & 0xFF) << 8);
            self.registers[address] = new_value;
        }
    }

    /// Write to low bit (bits 0-7).
    #[inline(always)]
    pub fn write_to_low_bit(&mut self, address: usize, value: i64) {
        if Self::is_register_address(address) && address < MAX_REGISTER_ADDRESS {
            if !self.registers_allocated[address] {
                self.registers_allocated[address] = true;
            }
            let current = self.registers[address];
            let new_value = (current & !0xFF) | (value & 0xFF);
            self.registers[address] = new_value;
        }
    }

    /// Update CPU flags based on a value.
    #[inline(always)]
    pub fn update_flags(&mut self, value: i64, size: u32) {
        let mask = if size >= 64 { i64::MAX } else { (1i64 << size) - 1 };
        let masked = value & mask;

        self.zero_flag = masked == 0;
        self.sign_flag = (masked & (1i64 << (size - 1))) != 0;

        // Overflow flag calculation
        let signed_min = -(1i64 << (size - 1));
        let signed_max = (1i64 << (size - 1)) - 1;
        self.overflow_flag = value < signed_min || value > signed_max;

        // Parity flag (count of 1 bits in low byte)
        self.parity_flag = ((masked & 0xFF) as u8).count_ones() % 2 == 0;
    }

    /// Increment a register.
    #[inline(always)]
    pub fn increment(&mut self, address: usize) {
        self.add(address, 1);
    }

    /// Decrement a register.
    #[inline(always)]
    pub fn decrement(&mut self, address: usize) {
        self.sub(address, 1);
    }

    /// Add to a register.
    #[inline(always)]
    pub fn add(&mut self, address: usize, value: i64) {
        let current = self.fetch(address) & 0xFF;
        self.write_16bit(address, current + value);
    }

    /// Subtract from a register.
    #[inline(always)]
    pub fn sub(&mut self, address: usize, value: i64) {
        self.add(address, -value);
    }

    // Flag getters
    #[inline(always)]
    pub fn zero_flag(&self) -> bool {
        self.zero_flag
    }

    #[inline(always)]
    pub fn sign_flag(&self) -> bool {
        self.sign_flag
    }

    #[inline(always)]
    pub fn overflow_flag(&self) -> bool {
        self.overflow_flag
    }

    #[inline(always)]
    pub fn carry_flag(&self) -> bool {
        self.carry_flag
    }

    #[inline(always)]
    pub fn parity_flag(&self) -> bool {
        self.parity_flag
    }

    #[inline(always)]
    pub fn auxiliary_carry_flag(&self) -> bool {
        self.auxiliary_carry_flag
    }

    #[inline(always)]
    pub fn direction_flag(&self) -> bool {
        self.direction_flag
    }

    #[inline(always)]
    pub fn interrupt_flag(&self) -> bool {
        self.interrupt_flag
    }

    // Flag setters
    #[inline(always)]
    pub fn set_zero_flag(&mut self, value: bool) {
        self.zero_flag = value;
    }

    #[inline(always)]
    pub fn set_sign_flag(&mut self, value: bool) {
        self.sign_flag = value;
    }

    #[inline(always)]
    pub fn set_overflow_flag(&mut self, value: bool) {
        self.overflow_flag = value;
    }

    #[inline(always)]
    pub fn set_carry_flag(&mut self, value: bool) {
        self.carry_flag = value;
    }

    #[inline(always)]
    pub fn set_parity_flag(&mut self, value: bool) {
        self.parity_flag = value;
    }

    #[inline(always)]
    pub fn set_auxiliary_carry_flag(&mut self, value: bool) {
        self.auxiliary_carry_flag = value;
    }

    #[inline(always)]
    pub fn set_direction_flag(&mut self, value: bool) {
        self.direction_flag = value;
    }

    #[inline(always)]
    pub fn set_interrupt_flag(&mut self, value: bool) {
        self.interrupt_flag = value;
    }

    #[inline(always)]
    pub fn set_instruction_fetch(&mut self, value: bool) {
        self.instruction_fetch = value;
    }

    #[inline(always)]
    pub fn instruction_fetch(&self) -> bool {
        self.instruction_fetch
    }

    // Control register operations
    #[inline(always)]
    pub fn read_control_register(&self, index: usize) -> u32 {
        if index < 5 {
            self.control_registers[index]
        } else {
            0
        }
    }

    #[inline(always)]
    pub fn write_control_register(&mut self, index: usize, value: u32) {
        if index < 5 {
            self.control_registers[index] = value;
        }
    }

    // EFER operations
    #[inline(always)]
    pub fn read_efer(&self) -> u64 {
        self.efer
    }

    #[inline(always)]
    pub fn write_efer(&mut self, value: u64) {
        self.efer = value;
    }

    /// Read a byte from memory.
    #[inline(always)]
    pub fn read_from_memory(&self, address: usize) -> u8 {
        unsafe {
            if !self.memory.is_null() {
                (*self.memory).read_byte_at(address)
            } else {
                0
            }
        }
    }

    /// Write a byte to memory.
    #[inline(always)]
    pub fn write_to_memory(&mut self, address: usize, value: u8) {
        unsafe {
            if !self.memory.is_null() {
                (*self.memory).write_byte_at(address, value);
            }
        }
    }

    /// Read a raw byte from memory.
    #[inline(always)]
    pub fn read_raw_byte(&self, address: usize) -> u8 {
        self.read_from_memory(address)
    }

    /// Write a raw byte to memory.
    #[inline(always)]
    pub fn write_raw_byte(&mut self, address: usize, value: u8) {
        self.write_to_memory(address, value);
    }

    /// Read a 32-bit value from physical memory.
    #[inline(always)]
    pub fn read_physical_32(&self, address: usize) -> u32 {
        let b0 = self.read_from_memory(address) as u32;
        let b1 = self.read_from_memory(address + 1) as u32;
        let b2 = self.read_from_memory(address + 2) as u32;
        let b3 = self.read_from_memory(address + 3) as u32;
        b0 | (b1 << 8) | (b2 << 16) | (b3 << 24)
    }

    /// Write a 32-bit value to physical memory.
    #[inline(always)]
    pub fn write_physical_32(&mut self, address: usize, value: u32) {
        self.write_to_memory(address, (value & 0xFF) as u8);
        self.write_to_memory(address + 1, ((value >> 8) & 0xFF) as u8);
        self.write_to_memory(address + 2, ((value >> 16) & 0xFF) as u8);
        self.write_to_memory(address + 3, ((value >> 24) & 0xFF) as u8);
    }

    /// Read a 64-bit value from physical memory.
    #[inline(always)]
    pub fn read_physical_64(&self, address: usize) -> u64 {
        let low = self.read_physical_32(address) as u64;
        let high = self.read_physical_32(address + 4) as u64;
        low | (high << 32)
    }

    /// Write a 64-bit value to physical memory.
    #[inline(always)]
    pub fn write_physical_64(&mut self, address: usize, value: u64) {
        self.write_physical_32(address, (value & 0xFFFFFFFF) as u32);
        self.write_physical_32(address + 4, ((value >> 32) & 0xFFFFFFFF) as u32);
    }

    /// Read 8-bit value from physical memory.
    #[inline(always)]
    pub fn read_physical_8(&self, address: usize) -> u8 {
        self.read_from_memory(address)
    }

    /// Read 16-bit value from physical memory.
    #[inline(always)]
    pub fn read_physical_16(&self, address: usize) -> u16 {
        let lo = self.read_from_memory(address) as u16;
        let hi = self.read_from_memory(address + 1) as u16;
        (hi << 8) | lo
    }

    /// Check if address is in MMIO range (LAPIC or IOAPIC).
    /// Returns true if the address needs to be handled by PHP.
    #[inline(always)]
    pub fn is_mmio_address(address: usize) -> bool {
        // LAPIC: 0xFEE00000 - 0xFEE00FFF
        // IOAPIC: 0xFEC00000 - 0xFEC0001F
        (address >= 0xFEE00000 && address < 0xFEE01000) ||
        (address >= 0xFEC00000 && address < 0xFEC00020)
    }

    /// Translate linear address to physical address through paging.
    /// Returns: (physical_address, error_code) where error_code is 0 on success,
    /// or a packed value: (vector << 16) | error_code on page fault.
    /// If MMIO is detected, returns (address, 0xFFFFFFFF) to signal PHP should handle it.
    pub fn translate_linear(
        &mut self,
        linear: u64,
        is_write: bool,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> (u64, u32) {
        let linear = linear & linear_mask;

        if !paging_enabled {
            return (linear, 0);
        }

        let cr4 = self.control_registers[4];
        let pse = (cr4 & (1 << 4)) != 0;
        let pae = (cr4 & (1 << 5)) != 0;

        if pae {
            self.translate_linear_pae(linear, is_write, is_user)
        } else {
            self.translate_linear_32(linear, is_write, is_user, pse)
        }
    }

    /// 32-bit paging translation.
    fn translate_linear_32(
        &mut self,
        linear: u64,
        is_write: bool,
        is_user: bool,
        pse: bool,
    ) -> (u64, u32) {
        let cr3 = (self.control_registers[3] & 0xFFFFF000) as usize;
        let linear = linear as usize;
        let dir_index = (linear >> 22) & 0x3FF;
        let table_index = (linear >> 12) & 0x3FF;
        let offset = linear & 0xFFF;

        let pde_addr = (cr3 + (dir_index * 4)) & 0xFFFFFFFF;
        let pde = self.read_physical_32(pde_addr) as u64;

        // Check PDE present
        if (pde & 0x1) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | (if is_user { 0b100 } else { 0 });
            return (linear as u64, (0x0E << 16) | err);
        }

        // Check reserved bits
        if (pde & 0xFFFFFF000) == 0 {
            let err = 0x08 | (if self.instruction_fetch { 0x10 } else { 0 });
            return (linear as u64, (0x0E << 16) | err);
        }

        // Check user access
        if is_user && (pde & 0x4) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | 0b100 | 0b1;
            return (linear as u64, (0x0E << 16) | err);
        }

        // Check write access
        if is_write && (pde & 0x2) == 0 {
            let err = 0b10 | (if is_user { 0b100 } else { 0 }) | 0b1;
            return (linear as u64, (0x0E << 16) | err);
        }

        // Handle 4MB page (PSE)
        let is_4m = pse && ((pde & (1 << 7)) != 0);
        if is_4m {
            let base = (pde & 0xFFC00000) as usize;
            let mut pde = pde;
            pde |= 0x20; // Set accessed
            if is_write {
                pde |= 0x40; // Set dirty
            }
            self.write_physical_32(pde_addr, pde as u32);
            let phys = ((base + (linear & 0x3FFFFF)) & 0xFFFFFFFF) as u64;
            return (phys, 0);
        }

        // Read PTE
        let pte_addr = ((pde & 0xFFFFF000) as usize + (table_index * 4)) & 0xFFFFFFFF;
        let pte = self.read_physical_32(pte_addr) as u64;

        // Check PTE present
        if (pte & 0x1) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | (if is_user { 0b100 } else { 0 });
            return (linear as u64, (0x0E << 16) | err);
        }

        // Check reserved bits
        if (pte & 0xFFFFFF000) == 0 {
            let err = 0x08 | (if self.instruction_fetch { 0x10 } else { 0 });
            return (linear as u64, (0x0E << 16) | err);
        }

        // Check user access
        if is_user && (pte & 0x4) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | 0b100 | 0b1;
            return (linear as u64, (0x0E << 16) | err);
        }

        // Check write access
        if is_write && (pte & 0x2) == 0 {
            let err = 0b10 | (if is_user { 0b100 } else { 0 }) | 0b1;
            return (linear as u64, (0x0E << 16) | err);
        }

        // Set accessed/dirty bits
        let mut pde = pde;
        pde |= 0x20;
        self.write_physical_32(pde_addr, pde as u32);

        let mut pte = pte;
        pte |= 0x20;
        if is_write {
            pte |= 0x40;
        }
        self.write_physical_32(pte_addr, pte as u32);

        let phys = (((pte & 0xFFFFF000) as usize + offset) & 0xFFFFFFFF) as u64;
        (phys, 0)
    }

    /// PAE paging translation.
    fn translate_linear_pae(
        &mut self,
        linear: u64,
        is_write: bool,
        is_user: bool,
    ) -> (u64, u32) {
        let cr3 = (self.control_registers[3] & 0xFFFFF000) as usize;
        let linear_usize = linear as usize;
        let pdp_index = (linear_usize >> 30) & 0x3;
        let dir_index = (linear_usize >> 21) & 0x1FF;
        let table_index = (linear_usize >> 12) & 0x1FF;
        let offset = linear_usize & 0xFFF;

        // Read PDPTE
        let pdpte_addr = (cr3 + (pdp_index * 8)) & 0xFFFFFFFF;
        let pdpte = self.read_physical_64(pdpte_addr);

        // Check PDPTE present
        if (pdpte & 0x1) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | (if is_user { 0b100 } else { 0 });
            return (linear, (0x0E << 16) | err);
        }

        // Check user access
        if is_user && (pdpte & 0x4) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | 0b100 | 0b1;
            return (linear, (0x0E << 16) | err);
        }

        // Check write access
        if is_write && (pdpte & 0x2) == 0 {
            let err = 0b10 | (if is_user { 0b100 } else { 0 }) | 0b1;
            return (linear, (0x0E << 16) | err);
        }

        // Mark PDPTE accessed
        self.write_physical_64(pdpte_addr, pdpte | (1 << 5));

        // Read PDE
        let pde_addr = (((pdpte & 0xFFFFFF000) as usize) + (dir_index * 8)) & 0xFFFFFFFF;
        let pde = self.read_physical_64(pde_addr);

        // Check PDE present
        if (pde & 0x1) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | (if is_user { 0b100 } else { 0 });
            return (linear, (0x0E << 16) | err);
        }

        let is_large = (pde & (1 << 7)) != 0;

        // Check user access
        if is_user && (pde & 0x4) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | 0b100 | 0b1;
            return (linear, (0x0E << 16) | err);
        }

        // Check write access
        if is_write && (pde & 0x2) == 0 {
            let err = 0b10 | (if is_user { 0b100 } else { 0 }) | 0b1;
            return (linear, (0x0E << 16) | err);
        }

        // Handle 2MB large page
        if is_large {
            let mut pde = pde;
            pde |= 0x20;
            if is_write {
                pde |= 0x40;
            }
            self.write_physical_64(pde_addr, pde);
            let base = (pde & 0xFFE00000) as usize;
            let phys = ((base + (linear_usize & 0x1FFFFF)) & 0xFFFFFFFF) as u64;
            return (phys, 0);
        }

        // Read PTE
        let pte_addr = (((pde & 0xFFFFFF000) as usize) + (table_index * 8)) & 0xFFFFFFFF;
        let pte = self.read_physical_64(pte_addr);

        // Check PTE present
        if (pte & 0x1) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | (if is_user { 0b100 } else { 0 });
            return (linear, (0x0E << 16) | err);
        }

        // Check user access
        if is_user && (pte & 0x4) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | 0b100 | 0b1;
            return (linear, (0x0E << 16) | err);
        }

        // Check write access
        if is_write && (pte & 0x2) == 0 {
            let err = 0b10 | (if is_user { 0b100 } else { 0 }) | 0b1;
            return (linear, (0x0E << 16) | err);
        }

        // Set accessed/dirty bits
        self.write_physical_64(pde_addr, pde | 0x20);
        let mut pte_updated = pte | 0x20;
        if is_write {
            pte_updated |= 0x40;
        }
        self.write_physical_64(pte_addr, pte_updated);

        let phys = ((pte & 0xFFFFFF000) as usize + offset) as u64;
        (phys & 0xFFFFFFFF, 0)
    }

    /// Read memory with linear address translation.
    /// Returns (value, error_code). error_code is 0 on success.
    pub fn read_memory_8(
        &mut self,
        linear: u64,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> (u8, u32) {
        let (physical, err) = self.translate_linear(linear, false, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return (0, err);
        }
        if Self::is_mmio_address(physical as usize) {
            return (0, 0xFFFFFFFF); // Signal PHP to handle MMIO
        }
        (self.read_physical_8(physical as usize), 0)
    }

    /// Read 16-bit memory with linear address translation.
    pub fn read_memory_16(
        &mut self,
        linear: u64,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> (u16, u32) {
        let (physical, err) = self.translate_linear(linear, false, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return (0, err);
        }
        if Self::is_mmio_address(physical as usize) {
            return (0, 0xFFFFFFFF);
        }
        (self.read_physical_16(physical as usize), 0)
    }

    /// Read 32-bit memory with linear address translation.
    pub fn read_memory_32(
        &mut self,
        linear: u64,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> (u32, u32) {
        let (physical, err) = self.translate_linear(linear, false, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return (0, err);
        }
        if Self::is_mmio_address(physical as usize) {
            return (0, 0xFFFFFFFF);
        }
        (self.read_physical_32(physical as usize), 0)
    }

    /// Read 64-bit memory with linear address translation.
    pub fn read_memory_64(
        &mut self,
        linear: u64,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> (u64, u32) {
        let (physical, err) = self.translate_linear(linear, false, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return (0, err);
        }
        if Self::is_mmio_address(physical as usize) {
            return (0, 0xFFFFFFFF);
        }
        (self.read_physical_64(physical as usize), 0)
    }

    /// Write 8-bit memory with linear address translation.
    /// Returns error_code (0 on success).
    pub fn write_memory_8(
        &mut self,
        linear: u64,
        value: u8,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> u32 {
        let (physical, err) = self.translate_linear(linear, true, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return err;
        }
        if Self::is_mmio_address(physical as usize) {
            return 0xFFFFFFFF; // Signal PHP to handle MMIO
        }
        self.write_raw_byte(physical as usize, value);
        0
    }

    /// Write 16-bit memory with linear address translation.
    /// Returns error_code (0 on success).
    pub fn write_memory_16(
        &mut self,
        linear: u64,
        value: u16,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> u32 {
        let (physical, err) = self.translate_linear(linear, true, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return err;
        }
        if Self::is_mmio_address(physical as usize) {
            return 0xFFFFFFFF;
        }
        // Write little-endian
        self.write_raw_byte(physical as usize, (value & 0xFF) as u8);
        self.write_raw_byte((physical + 1) as usize, ((value >> 8) & 0xFF) as u8);
        0
    }

    /// Write 32-bit memory with linear address translation.
    /// Returns error_code (0 on success).
    pub fn write_memory_32(
        &mut self,
        linear: u64,
        value: u32,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> u32 {
        let (physical, err) = self.translate_linear(linear, true, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return err;
        }
        if Self::is_mmio_address(physical as usize) {
            return 0xFFFFFFFF;
        }
        self.write_physical_32(physical as usize, value);
        0
    }

    /// Write 64-bit memory with linear address translation.
    /// Returns error_code (0 on success).
    pub fn write_memory_64(
        &mut self,
        linear: u64,
        value: u64,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> u32 {
        let (physical, err) = self.translate_linear(linear, true, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return err;
        }
        if Self::is_mmio_address(physical as usize) {
            return 0xFFFFFFFF;
        }
        self.write_physical_64(physical as usize, value);
        0
    }

    /// Write 16-bit value to physical memory.
    #[inline(always)]
    pub fn write_physical_16(&mut self, address: usize, value: u16) {
        self.write_to_memory(address, (value & 0xFF) as u8);
        self.write_to_memory(address + 1, ((value >> 8) & 0xFF) as u8);
    }
}

// =============================================================================
// FFI exports for PHP
// =============================================================================

/// Create a new MemoryAccessor instance.
#[no_mangle]
pub extern "C" fn memory_accessor_new(memory: *mut MemoryStream) -> *mut MemoryAccessor {
    let accessor = Box::new(MemoryAccessor::new(memory));
    Box::into_raw(accessor)
}

/// Free a MemoryAccessor instance.
#[no_mangle]
pub extern "C" fn memory_accessor_free(accessor: *mut MemoryAccessor) {
    if !accessor.is_null() {
        unsafe {
            let _ = Box::from_raw(accessor);
        }
    }
}

/// Allocate a register or memory range.
#[no_mangle]
pub extern "C" fn memory_accessor_allocate(
    accessor: *mut MemoryAccessor,
    address: usize,
    size: usize,
    safe: bool,
) -> bool {
    unsafe { (*accessor).allocate(address, size, safe) }
}

/// Fetch a register value.
#[no_mangle]
pub extern "C" fn memory_accessor_fetch(accessor: *const MemoryAccessor, address: usize) -> i64 {
    unsafe { (*accessor).fetch(address) }
}

/// Fetch a register value with size.
#[no_mangle]
pub extern "C" fn memory_accessor_fetch_by_size(
    accessor: *const MemoryAccessor,
    address: usize,
    size: u32,
) -> i64 {
    unsafe { (*accessor).fetch_by_size(address, size) }
}

/// Try to fetch a register value.
#[no_mangle]
pub extern "C" fn memory_accessor_try_to_fetch(accessor: *const MemoryAccessor, address: usize) -> i64 {
    unsafe { (*accessor).try_to_fetch(address) }
}

/// Write a 16-bit value.
#[no_mangle]
pub extern "C" fn memory_accessor_write_16bit(accessor: *mut MemoryAccessor, address: usize, value: i64) {
    unsafe { (*accessor).write_16bit(address, value) }
}

/// Write a value by size.
#[no_mangle]
pub extern "C" fn memory_accessor_write_by_size(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: i64,
    size: u32,
) {
    unsafe { (*accessor).write_by_size(address, value, size) }
}

/// Write to high bit.
#[no_mangle]
pub extern "C" fn memory_accessor_write_to_high_bit(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: i64,
) {
    unsafe { (*accessor).write_to_high_bit(address, value) }
}

/// Write to low bit.
#[no_mangle]
pub extern "C" fn memory_accessor_write_to_low_bit(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: i64,
) {
    unsafe { (*accessor).write_to_low_bit(address, value) }
}

/// Update flags.
#[no_mangle]
pub extern "C" fn memory_accessor_update_flags(accessor: *mut MemoryAccessor, value: i64, size: u32) {
    unsafe { (*accessor).update_flags(value, size) }
}

/// Increment a register.
#[no_mangle]
pub extern "C" fn memory_accessor_increment(accessor: *mut MemoryAccessor, address: usize) {
    unsafe { (*accessor).increment(address) }
}

/// Decrement a register.
#[no_mangle]
pub extern "C" fn memory_accessor_decrement(accessor: *mut MemoryAccessor, address: usize) {
    unsafe { (*accessor).decrement(address) }
}

/// Add to a register.
#[no_mangle]
pub extern "C" fn memory_accessor_add(accessor: *mut MemoryAccessor, address: usize, value: i64) {
    unsafe { (*accessor).add(address, value) }
}

/// Subtract from a register.
#[no_mangle]
pub extern "C" fn memory_accessor_sub(accessor: *mut MemoryAccessor, address: usize, value: i64) {
    unsafe { (*accessor).sub(address, value) }
}

// Flag getters
#[no_mangle]
pub extern "C" fn memory_accessor_zero_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).zero_flag() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_sign_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).sign_flag() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_overflow_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).overflow_flag() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_carry_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).carry_flag() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_parity_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).parity_flag() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_auxiliary_carry_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).auxiliary_carry_flag() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_direction_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).direction_flag() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_interrupt_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).interrupt_flag() }
}

// Flag setters
#[no_mangle]
pub extern "C" fn memory_accessor_set_zero_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_zero_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_sign_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_sign_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_overflow_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_overflow_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_carry_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_carry_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_parity_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_parity_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_auxiliary_carry_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_auxiliary_carry_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_direction_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_direction_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_interrupt_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_interrupt_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_instruction_fetch(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_instruction_fetch(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_instruction_fetch(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).instruction_fetch() }
}

// Control register operations
#[no_mangle]
pub extern "C" fn memory_accessor_read_control_register(
    accessor: *const MemoryAccessor,
    index: usize,
) -> u32 {
    unsafe { (*accessor).read_control_register(index) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_write_control_register(
    accessor: *mut MemoryAccessor,
    index: usize,
    value: u32,
) {
    unsafe { (*accessor).write_control_register(index, value) }
}

// EFER operations
#[no_mangle]
pub extern "C" fn memory_accessor_read_efer(accessor: *const MemoryAccessor) -> u64 {
    unsafe { (*accessor).read_efer() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_write_efer(accessor: *mut MemoryAccessor, value: u64) {
    unsafe { (*accessor).write_efer(value) }
}

// Memory operations
#[no_mangle]
pub extern "C" fn memory_accessor_read_from_memory(accessor: *const MemoryAccessor, address: usize) -> u8 {
    unsafe { (*accessor).read_from_memory(address) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_write_to_memory(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: u8,
) {
    unsafe { (*accessor).write_to_memory(address, value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_read_raw_byte(accessor: *const MemoryAccessor, address: usize) -> u8 {
    unsafe { (*accessor).read_raw_byte(address) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_write_raw_byte(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: u8,
) {
    unsafe { (*accessor).write_raw_byte(address, value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_read_physical_32(accessor: *const MemoryAccessor, address: usize) -> u32 {
    unsafe { (*accessor).read_physical_32(address) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_write_physical_32(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: u32,
) {
    unsafe { (*accessor).write_physical_32(address, value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_read_physical_64(accessor: *const MemoryAccessor, address: usize) -> u64 {
    unsafe { (*accessor).read_physical_64(address) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_write_physical_64(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: u64,
) {
    unsafe { (*accessor).write_physical_64(address, value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_read_physical_8(accessor: *const MemoryAccessor, address: usize) -> u8 {
    unsafe { (*accessor).read_physical_8(address) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_read_physical_16(accessor: *const MemoryAccessor, address: usize) -> u16 {
    unsafe { (*accessor).read_physical_16(address) }
}

/// Translate linear address to physical address.
/// Returns physical address in low 32/64 bits.
/// If there's a page fault, returns error info packed as:
/// - result_physical: the faulting linear address
/// - result_error: (vector << 16) | error_code, or 0xFFFFFFFF for MMIO
#[no_mangle]
pub extern "C" fn memory_accessor_translate_linear(
    accessor: *mut MemoryAccessor,
    linear: u64,
    is_write: bool,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
    result_physical: *mut u64,
    result_error: *mut u32,
) {
    unsafe {
        let (phys, err) = (*accessor).translate_linear(linear, is_write, is_user, paging_enabled, linear_mask);
        *result_physical = phys;
        *result_error = err;
    }
}

/// Check if address is in MMIO range.
#[no_mangle]
pub extern "C" fn memory_accessor_is_mmio_address(address: usize) -> bool {
    MemoryAccessor::is_mmio_address(address)
}

/// Read 8-bit memory with linear address translation.
/// Returns value in result_value, error in result_error.
/// If result_error == 0xFFFFFFFF, MMIO handling is needed.
#[no_mangle]
pub extern "C" fn memory_accessor_read_memory_8(
    accessor: *mut MemoryAccessor,
    linear: u64,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
    result_value: *mut u8,
    result_error: *mut u32,
) {
    unsafe {
        let (val, err) = (*accessor).read_memory_8(linear, is_user, paging_enabled, linear_mask);
        *result_value = val;
        *result_error = err;
    }
}

/// Read 16-bit memory with linear address translation.
#[no_mangle]
pub extern "C" fn memory_accessor_read_memory_16(
    accessor: *mut MemoryAccessor,
    linear: u64,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
    result_value: *mut u16,
    result_error: *mut u32,
) {
    unsafe {
        let (val, err) = (*accessor).read_memory_16(linear, is_user, paging_enabled, linear_mask);
        *result_value = val;
        *result_error = err;
    }
}

/// Read 32-bit memory with linear address translation.
#[no_mangle]
pub extern "C" fn memory_accessor_read_memory_32(
    accessor: *mut MemoryAccessor,
    linear: u64,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
    result_value: *mut u32,
    result_error: *mut u32,
) {
    unsafe {
        let (val, err) = (*accessor).read_memory_32(linear, is_user, paging_enabled, linear_mask);
        *result_value = val;
        *result_error = err;
    }
}

/// Read 64-bit memory with linear address translation.
#[no_mangle]
pub extern "C" fn memory_accessor_read_memory_64(
    accessor: *mut MemoryAccessor,
    linear: u64,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
    result_value: *mut u64,
    result_error: *mut u32,
) {
    unsafe {
        let (val, err) = (*accessor).read_memory_64(linear, is_user, paging_enabled, linear_mask);
        *result_value = val;
        *result_error = err;
    }
}

/// Write 8-bit memory with linear address translation.
/// Returns error code (0 on success, 0xFFFFFFFF for MMIO).
#[no_mangle]
pub extern "C" fn memory_accessor_write_memory_8(
    accessor: *mut MemoryAccessor,
    linear: u64,
    value: u8,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
) -> u32 {
    unsafe { (*accessor).write_memory_8(linear, value, is_user, paging_enabled, linear_mask) }
}

/// Write 16-bit memory with linear address translation.
/// Returns error code (0 on success, 0xFFFFFFFF for MMIO).
#[no_mangle]
pub extern "C" fn memory_accessor_write_memory_16(
    accessor: *mut MemoryAccessor,
    linear: u64,
    value: u16,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
) -> u32 {
    unsafe { (*accessor).write_memory_16(linear, value, is_user, paging_enabled, linear_mask) }
}

/// Write 32-bit memory with linear address translation.
/// Returns error code (0 on success, 0xFFFFFFFF for MMIO).
#[no_mangle]
pub extern "C" fn memory_accessor_write_memory_32(
    accessor: *mut MemoryAccessor,
    linear: u64,
    value: u32,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
) -> u32 {
    unsafe { (*accessor).write_memory_32(linear, value, is_user, paging_enabled, linear_mask) }
}

/// Write 64-bit memory with linear address translation.
/// Returns error code (0 on success, 0xFFFFFFFF for MMIO).
#[no_mangle]
pub extern "C" fn memory_accessor_write_memory_64(
    accessor: *mut MemoryAccessor,
    linear: u64,
    value: u64,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
) -> u32 {
    unsafe { (*accessor).write_memory_64(linear, value, is_user, paging_enabled, linear_mask) }
}

/// Write 16-bit value to physical memory.
#[no_mangle]
pub extern "C" fn memory_accessor_write_physical_16(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: u16,
) {
    unsafe { (*accessor).write_physical_16(address, value) }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_register_operations() {
        let mut memory = MemoryStream::new(1024, 16 * 1024 * 1024, 256 * 1024 * 1024);
        let mut accessor = MemoryAccessor::new(&mut memory as *mut MemoryStream);

        // Allocate EAX (address 0)
        accessor.allocate(0, 1, true);

        // Write and read
        accessor.write_by_size(0, 0x12345678, 32);
        assert_eq!(accessor.fetch_by_size(0, 32), 0x12345678);

        // Test 16-bit write preserves upper bits
        accessor.write_by_size(0, 0xABCD, 16);
        // In x86-64, 16-bit writes preserve upper bits
        assert_eq!(accessor.fetch_by_size(0, 16), 0xABCD);
    }

    #[test]
    fn test_flags() {
        let mut memory = MemoryStream::new(1024, 16 * 1024 * 1024, 256 * 1024 * 1024);
        let mut accessor = MemoryAccessor::new(&mut memory as *mut MemoryStream);

        // Test zero flag
        accessor.update_flags(0, 16);
        assert!(accessor.zero_flag());

        // Test sign flag
        accessor.update_flags(-1, 16);
        assert!(accessor.sign_flag());

        // Test parity flag
        accessor.update_flags(0xFF, 16);
        assert!(accessor.parity_flag()); // 8 ones = even
    }
}
