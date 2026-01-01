use super::super::MemoryAccessor;

impl MemoryAccessor {
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

    /// Check if address is in MMIO range (LAPIC, IOAPIC, or device-mapped regions).
    /// Returns true if the address needs to be handled by PHP.
    #[inline(always)]
    pub fn is_mmio_address(address: usize) -> bool {
        // PCI VGA BAR (linear framebuffer): 0xE0000000 - 0xE0FFFFFF
        (address >= 0xE0000000 && address < 0xE1000000) ||
        // LAPIC: 0xFEE00000 - 0xFEE00FFF
        // IOAPIC: 0xFEC00000 - 0xFEC0001F
        (address >= 0xFEE00000 && address < 0xFEE01000) ||
        (address >= 0xFEC00000 && address < 0xFEC00020)
    }
}
