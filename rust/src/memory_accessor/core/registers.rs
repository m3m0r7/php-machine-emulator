use crate::memory_stream::MemoryStream;
use super::super::{MemoryAccessor, MAX_REGISTER_ADDRESS};

impl MemoryAccessor {
    /// Create a new MemoryAccessor.
    pub fn new(memory: *mut MemoryStream) -> Self {
        MemoryAccessor {
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
            control_registers: [0x22, 0, 0, 0, 0, 0, 0, 0, 0], // CR0: MP + NE set
            memory,
        }
    }

    /// Check if address is a register address.
    #[inline(always)]
    fn is_register_address(address: usize) -> bool {
        (0..=13).contains(&address) || (16..=25).contains(&address)
    }

    /// Check if address is a GPR address.
    #[inline(always)]
    fn is_gpr_address(address: usize) -> bool {
        (0..=7).contains(&address) || (16..=24).contains(&address)
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
}
