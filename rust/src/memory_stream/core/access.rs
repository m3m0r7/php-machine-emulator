use std::cmp;

use super::{MemoryStream, PAGE_MASK, PAGE_SHIFT, PAGE_SIZE};

impl MemoryStream {
    /// Read a single character at current offset.
    #[inline(always)]
    pub fn char(&mut self) -> u8 {
        if self.offset >= self.size {
            if self.offset >= self.logical_max_memory_size() {
                return 0;
            }
            let _ = self.ensure_capacity(self.offset);
        }

        let value = self.read_byte_at(self.offset);
        self.offset += 1;
        value
    }

    /// Read a single byte at current offset (same as char but more explicit).
    #[inline(always)]
    pub fn byte(&mut self) -> u8 {
        self.char()
    }

    /// Read a signed byte at current offset.
    #[inline(always)]
    pub fn signed_byte(&mut self) -> i8 {
        self.byte() as i8
    }

    /// Read a 16-bit little-endian value at current offset.
    #[inline(always)]
    pub fn short(&mut self) -> u16 {
        let low = self.byte() as u16;
        let high = self.byte() as u16;
        low | (high << 8)
    }

    /// Read a 32-bit little-endian value at current offset.
    #[inline(always)]
    pub fn dword(&mut self) -> u32 {
        let b0 = self.byte() as u32;
        let b1 = self.byte() as u32;
        let b2 = self.byte() as u32;
        let b3 = self.byte() as u32;
        b0 | (b1 << 8) | (b2 << 16) | (b3 << 24)
    }

    /// Read a 64-bit little-endian value at current offset.
    #[inline(always)]
    pub fn qword(&mut self) -> u64 {
        let low = self.dword() as u64;
        let high = self.dword() as u64;
        low | (high << 32)
    }

    /// Read multiple bytes at current offset.
    pub fn read(&mut self, length: usize) -> Vec<u8> {
        if length == 0 {
            return Vec::new();
        }

        let end_offset = self.offset + length;
        if end_offset > self.size {
            let _ = self.ensure_capacity(end_offset);
        }

        let mut result = vec![0u8; length];
        let available = self.size.saturating_sub(self.offset);
        let actual_len = cmp::min(length, available);
        if actual_len > 0 {
            self.read_slice_at(self.offset, &mut result[..actual_len]);
        }

        self.offset += length;
        result
    }

    /// Read bytes into a pre-allocated buffer.
    pub fn read_into(&mut self, buffer: &mut [u8]) -> usize {
        let length = buffer.len();
        if length == 0 {
            return 0;
        }

        let end_offset = self.offset + length;
        if end_offset > self.size {
            let _ = self.ensure_capacity(end_offset);
        }

        let available = self.size.saturating_sub(self.offset);
        let actual_len = cmp::min(length, available);

        if actual_len > 0 {
            self.read_slice_at(self.offset, &mut buffer[..actual_len]);
        }

        // Fill remaining with zeros if needed
        if actual_len < length {
            buffer[actual_len..].fill(0);
        }

        self.offset += length;
        length
    }

    /// Write a string/bytes at current offset.
    pub fn write(&mut self, value: &[u8]) {
        let len = value.len();
        if len == 0 {
            return;
        }

        let logical_max = self.logical_max_memory_size();
        if self.offset >= logical_max {
            return;
        }

        let write_len = cmp::min(len, logical_max - self.offset);
        let end_offset = self.offset + write_len;
        if end_offset >= self.size {
            let _ = self.ensure_capacity(end_offset);
        }

        self.write_slice_at(self.offset, &value[..write_len]);
        self.offset += write_len;
    }

    /// Write a single byte at current offset.
    #[inline(always)]
    pub fn write_byte(&mut self, value: u8) {
        if self.offset >= self.logical_max_memory_size() {
            return;
        }
        self.write_byte_at(self.offset, value);
        self.offset += 1;
    }

    /// Write a 16-bit little-endian value at current offset.
    #[inline(always)]
    pub fn write_short(&mut self, value: u16) {
        self.write_byte((value & 0xFF) as u8);
        self.write_byte(((value >> 8) & 0xFF) as u8);
    }

    /// Write a 32-bit little-endian value at current offset.
    #[inline(always)]
    pub fn write_dword(&mut self, value: u32) {
        self.write_byte((value & 0xFF) as u8);
        self.write_byte(((value >> 8) & 0xFF) as u8);
        self.write_byte(((value >> 16) & 0xFF) as u8);
        self.write_byte(((value >> 24) & 0xFF) as u8);
    }

    /// Write a 64-bit little-endian value at current offset.
    #[inline(always)]
    pub fn write_qword(&mut self, value: u64) {
        self.write_dword((value & 0xFFFFFFFF) as u32);
        self.write_dword(((value >> 32) & 0xFFFFFFFF) as u32);
    }

    /// Read a byte at a specific address without changing offset.
    #[inline(always)]
    pub fn read_byte_at(&self, address: usize) -> u8 {
        if address >= self.size {
            return 0;
        }

        let page_index = address >> PAGE_SHIFT;
        if page_index >= self.pages.len() {
            return 0;
        }
        let page_off = address & PAGE_MASK;

        match &self.pages[page_index] {
            Some(page) => page[page_off],
            None => 0,
        }
    }

    /// Write a byte at a specific address without changing offset.
    #[inline(always)]
    pub fn write_byte_at(&mut self, address: usize, value: u8) {
        if address >= self.logical_max_memory_size() {
            return;
        }

        if address >= self.size {
            let _ = self.ensure_capacity(address);
        }

        if address >= self.size {
            return;
        }

        let page_index = address >> PAGE_SHIFT;
        if page_index >= self.pages.len() {
            return;
        }
        let page_off = address & PAGE_MASK;

        if self.pages[page_index].is_none() {
            self.pages[page_index] = Some(Box::new([0u8; PAGE_SIZE]));
        }
        if let Some(page) = self.pages[page_index].as_mut() {
            page[page_off] = value;
        }
    }

    /// Read a 16-bit value at a specific address without changing offset.
    #[inline(always)]
    pub fn read_short_at(&self, address: usize) -> u16 {
        let low = self.read_byte_at(address) as u16;
        let high = self.read_byte_at(address + 1) as u16;
        low | (high << 8)
    }

    /// Write a 16-bit value at a specific address without changing offset.
    #[inline(always)]
    pub fn write_short_at(&mut self, address: usize, value: u16) {
        self.write_byte_at(address, (value & 0xFF) as u8);
        self.write_byte_at(address + 1, ((value >> 8) & 0xFF) as u8);
    }

    /// Read a 32-bit value at a specific address without changing offset.
    #[inline(always)]
    pub fn read_dword_at(&self, address: usize) -> u32 {
        let b0 = self.read_byte_at(address) as u32;
        let b1 = self.read_byte_at(address + 1) as u32;
        let b2 = self.read_byte_at(address + 2) as u32;
        let b3 = self.read_byte_at(address + 3) as u32;
        b0 | (b1 << 8) | (b2 << 16) | (b3 << 24)
    }

    /// Write a 32-bit value at a specific address without changing offset.
    #[inline(always)]
    pub fn write_dword_at(&mut self, address: usize, value: u32) {
        self.write_byte_at(address, (value & 0xFF) as u8);
        self.write_byte_at(address + 1, ((value >> 8) & 0xFF) as u8);
        self.write_byte_at(address + 2, ((value >> 16) & 0xFF) as u8);
        self.write_byte_at(address + 3, ((value >> 24) & 0xFF) as u8);
    }

    /// Read a 64-bit value at a specific address without changing offset.
    #[inline(always)]
    pub fn read_qword_at(&self, address: usize) -> u64 {
        let low = self.read_dword_at(address) as u64;
        let high = self.read_dword_at(address + 4) as u64;
        low | (high << 32)
    }

    /// Write a 64-bit value at a specific address without changing offset.
    #[inline(always)]
    pub fn write_qword_at(&mut self, address: usize, value: u64) {
        self.write_dword_at(address, (value & 0xFFFFFFFF) as u32);
        self.write_dword_at(address + 4, ((value >> 32) & 0xFFFFFFFF) as u32);
    }

    fn read_slice_at(&self, address: usize, out: &mut [u8]) {
        if out.is_empty() {
            return;
        }

        let mut addr = address;
        let mut dst = 0usize;

        while dst < out.len() {
            let page_index = addr >> PAGE_SHIFT;
            let page_off = addr & PAGE_MASK;
            let chunk = cmp::min(out.len() - dst, PAGE_SIZE - page_off);

            if page_index < self.pages.len() && addr < self.size {
                if let Some(page) = &self.pages[page_index] {
                    out[dst..dst + chunk].copy_from_slice(&page[page_off..page_off + chunk]);
                } else {
                    out[dst..dst + chunk].fill(0);
                }
            } else {
                out[dst..dst + chunk].fill(0);
            }

            addr += chunk;
            dst += chunk;
        }
    }

    fn write_slice_at(&mut self, address: usize, data: &[u8]) {
        if data.is_empty() {
            return;
        }

        let logical_max = self.logical_max_memory_size();
        if address >= logical_max {
            return;
        }

        let write_len = cmp::min(data.len(), logical_max - address);
        if write_len == 0 {
            return;
        }

        let end = address + write_len;
        if end >= self.size {
            let _ = self.ensure_capacity(end);
        }

        let mut addr = address;
        let mut src = 0usize;
        let last = address + write_len;

        while addr < last {
            let page_index = addr >> PAGE_SHIFT;
            if page_index >= self.pages.len() {
                break;
            }
            let page_off = addr & PAGE_MASK;
            let chunk = cmp::min(last - addr, PAGE_SIZE - page_off);

            if self.pages[page_index].is_none() {
                self.pages[page_index] = Some(Box::new([0u8; PAGE_SIZE]));
            }
            if let Some(page) = self.pages[page_index].as_mut() {
                page[page_off..page_off + chunk].copy_from_slice(&data[src..src + chunk]);
            }

            addr += chunk;
            src += chunk;
        }
    }
}
