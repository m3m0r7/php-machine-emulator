//! High-performance memory stream implementation for x86 emulation.
//!
//! This module provides a Rust implementation of MemoryStream that can be called
//! from PHP via FFI.
//!
//! NOTE: A contiguous `Vec<u8>` implementation performs well for small/medium
//! memory sizes, but becomes prohibitively slow when the guest touches sparse
//! high addresses (e.g. near 2GB) because `Vec::resize()` must allocate and
//! zero-fill the entire range up to that address.
//!
//! To keep Ubuntu/GRUB boot practical, we use a sparse page-backed model:
//! - Unallocated pages read as zero
//! - Pages are allocated (zeroed) only on first write
//! - The logical address space remains `physical_max_memory_size + swap_size`

use std::{cmp, slice};

/// Expansion chunk size (1MB)
const EXPANSION_CHUNK_SIZE: usize = 0x100000;

/// Page size (4KB)
const PAGE_SIZE: usize = 0x1000;
const PAGE_SHIFT: usize = 12;
const PAGE_MASK: usize = PAGE_SIZE - 1;

/// Memory stream structure with sparse page-backed memory.
#[repr(C)]
pub struct MemoryStream {
    /// Sparse pages (None => implicitly zero-filled)
    pages: Vec<Option<Box<[u8; PAGE_SIZE]>>>,
    /// Current read/write offset
    offset: usize,
    /// Current allocated size
    size: usize,
    /// Physical maximum memory size (without swap)
    physical_max_memory_size: usize,
    /// Swap size
    swap_size: usize,
}

impl MemoryStream {
    /// Create a new memory stream with the specified configuration.
    ///
    /// # Arguments
    /// * `size` - Initial memory size (default 1MB)
    /// * `physical_max_memory_size` - Maximum physical memory size (default 16MB)
    /// * `swap_size` - Swap size for overflow (default 256MB)
    pub fn new(size: usize, physical_max_memory_size: usize, swap_size: usize) -> Self {
        let logical_max = physical_max_memory_size + swap_size;
        let page_count = if logical_max == 0 {
            0
        } else {
            (logical_max + PAGE_SIZE - 1) >> PAGE_SHIFT
        };

        MemoryStream {
            pages: vec![None; page_count],
            offset: 0,
            size: cmp::min(size, logical_max),
            physical_max_memory_size,
            swap_size,
        }
    }

    /// Get the logical maximum memory size (physical + swap).
    #[inline(always)]
    pub fn logical_max_memory_size(&self) -> usize {
        self.physical_max_memory_size + self.swap_size
    }

    /// Get the physical maximum memory size.
    #[inline(always)]
    pub fn physical_max_memory_size(&self) -> usize {
        self.physical_max_memory_size
    }

    /// Get the swap size.
    #[inline(always)]
    pub fn swap_size(&self) -> usize {
        self.swap_size
    }

    /// Get the current allocated size.
    #[inline(always)]
    pub fn size(&self) -> usize {
        self.size
    }

    /// Expand memory if needed to accommodate the given offset.
    pub fn ensure_capacity(&mut self, required_offset: usize) -> bool {
        if required_offset < self.size {
            return true;
        }

        let logical_max = self.logical_max_memory_size();
        if required_offset >= logical_max {
            return false;
        }

        // Calculate new size in chunk increments
        let new_size = std::cmp::min(
            logical_max,
            ((required_offset + 1 + EXPANSION_CHUNK_SIZE - 1) / EXPANSION_CHUNK_SIZE) * EXPANSION_CHUNK_SIZE,
        );
        self.size = new_size;

        true
    }

    /// Get current offset.
    #[inline(always)]
    pub fn offset(&self) -> usize {
        self.offset
    }

    /// Set current offset.
    #[inline(always)]
    pub fn set_offset(&mut self, new_offset: usize) -> bool {
        if new_offset >= self.logical_max_memory_size() {
            return false;
        }

        if new_offset >= self.size {
            if !self.ensure_capacity(new_offset) {
                return false;
            }
        }
        self.offset = new_offset;
        true
    }

    /// Check if at end of file.
    #[inline(always)]
    pub fn is_eof(&self) -> bool {
        self.offset >= self.size && self.offset >= self.logical_max_memory_size()
    }

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

    /// Copy data from source to destination within the same memory.
    pub fn copy_internal(&mut self, src_offset: usize, dest_offset: usize, size: usize) {
        if size == 0 {
            return;
        }

        let logical_max = self.logical_max_memory_size();
        if src_offset >= logical_max || dest_offset >= logical_max {
            return;
        }

        // Clamp to the logical address space.
        let max_size = cmp::min(
            size,
            cmp::min(logical_max - src_offset, logical_max - dest_offset),
        );
        if max_size == 0 || src_offset == dest_offset {
            return;
        }

        // Ensure both ranges exist. Reads are zero-filled for unallocated pages,
        // but size/offset semantics require that the stream considers these regions "allocated".
        let src_end = src_offset + max_size;
        let dest_end = dest_offset + max_size;
        let _ = self.ensure_capacity(src_end);
        let _ = self.ensure_capacity(dest_end);

        const CHUNK: usize = 64 * 1024;
        let mut buffer = vec![0u8; CHUNK];

        // memmove overlap detection
        let overlap = src_offset < dest_offset && (src_offset + max_size) > dest_offset;
        if overlap {
            let mut remaining = max_size;
            while remaining > 0 {
                let chunk = cmp::min(CHUNK, remaining);
                let start = remaining - chunk;
                self.read_slice_at(src_offset + start, &mut buffer[..chunk]);
                self.write_slice_at(dest_offset + start, &buffer[..chunk]);
                remaining -= chunk;
            }
            return;
        }

        let mut offset = 0usize;
        while offset < max_size {
            let chunk = cmp::min(CHUNK, max_size - offset);
            self.read_slice_at(src_offset + offset, &mut buffer[..chunk]);
            self.write_slice_at(dest_offset + offset, &buffer[..chunk]);
            offset += chunk;
        }
    }

    /// Copy data from an external buffer to this memory.
    pub fn copy_from_external(&mut self, src: &[u8], dest_offset: usize) {
        let size = src.len();
        if size == 0 {
            return;
        }

        if dest_offset >= self.logical_max_memory_size() {
            return;
        }

        let write_len = cmp::min(size, self.logical_max_memory_size() - dest_offset);
        let dest_end = dest_offset + write_len;
        if dest_end >= self.size {
            let _ = self.ensure_capacity(dest_end);
        }

        self.write_slice_at(dest_offset, &src[..write_len]);
    }

    /// Get a direct pointer to the internal memory buffer.
    /// This is useful for FFI when PHP needs direct memory access.
    pub fn as_ptr(&self) -> *const u8 {
        std::ptr::null()
    }

    /// Get a mutable pointer to the internal memory buffer.
    pub fn as_mut_ptr(&mut self) -> *mut u8 {
        std::ptr::null_mut()
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

// =============================================================================
// FFI exports for PHP
// =============================================================================

/// Create a new MemoryStream instance.
#[no_mangle]
pub extern "C" fn memory_stream_new(
    size: usize,
    physical_max_memory_size: usize,
    swap_size: usize,
) -> *mut MemoryStream {
    let stream = Box::new(MemoryStream::new(size, physical_max_memory_size, swap_size));
    Box::into_raw(stream)
}

/// Free a MemoryStream instance.
#[no_mangle]
pub extern "C" fn memory_stream_free(stream: *mut MemoryStream) {
    if !stream.is_null() {
        unsafe {
            let _ = Box::from_raw(stream);
        }
    }
}

/// Get the logical max memory size.
#[no_mangle]
pub extern "C" fn memory_stream_logical_max_memory_size(stream: *const MemoryStream) -> usize {
    unsafe {
        (*stream).logical_max_memory_size()
    }
}

/// Get the physical max memory size.
#[no_mangle]
pub extern "C" fn memory_stream_physical_max_memory_size(stream: *const MemoryStream) -> usize {
    unsafe {
        (*stream).physical_max_memory_size()
    }
}

/// Get the swap size.
#[no_mangle]
pub extern "C" fn memory_stream_swap_size(stream: *const MemoryStream) -> usize {
    unsafe {
        (*stream).swap_size()
    }
}

/// Get the current size.
#[no_mangle]
pub extern "C" fn memory_stream_size(stream: *const MemoryStream) -> usize {
    unsafe {
        (*stream).size()
    }
}

/// Ensure capacity for the given offset.
#[no_mangle]
pub extern "C" fn memory_stream_ensure_capacity(stream: *mut MemoryStream, required_offset: usize) -> bool {
    unsafe {
        (*stream).ensure_capacity(required_offset)
    }
}

/// Get current offset.
#[no_mangle]
pub extern "C" fn memory_stream_offset(stream: *const MemoryStream) -> usize {
    unsafe {
        (*stream).offset()
    }
}

/// Set current offset.
#[no_mangle]
pub extern "C" fn memory_stream_set_offset(stream: *mut MemoryStream, new_offset: usize) -> bool {
    unsafe {
        (*stream).set_offset(new_offset)
    }
}

/// Check if at EOF.
#[no_mangle]
pub extern "C" fn memory_stream_is_eof(stream: *const MemoryStream) -> bool {
    unsafe {
        (*stream).is_eof()
    }
}

/// Read a single byte.
#[no_mangle]
pub extern "C" fn memory_stream_byte(stream: *mut MemoryStream) -> u8 {
    unsafe {
        (*stream).byte()
    }
}

/// Read a signed byte.
#[no_mangle]
pub extern "C" fn memory_stream_signed_byte(stream: *mut MemoryStream) -> i8 {
    unsafe {
        (*stream).signed_byte()
    }
}

/// Read a 16-bit value.
#[no_mangle]
pub extern "C" fn memory_stream_short(stream: *mut MemoryStream) -> u16 {
    unsafe {
        (*stream).short()
    }
}

/// Read a 32-bit value.
#[no_mangle]
pub extern "C" fn memory_stream_dword(stream: *mut MemoryStream) -> u32 {
    unsafe {
        (*stream).dword()
    }
}

/// Read a 64-bit value.
#[no_mangle]
pub extern "C" fn memory_stream_qword(stream: *mut MemoryStream) -> u64 {
    unsafe {
        (*stream).qword()
    }
}

/// Read multiple bytes into a buffer.
/// Returns the number of bytes read.
#[no_mangle]
pub extern "C" fn memory_stream_read(
    stream: *mut MemoryStream,
    buffer: *mut u8,
    length: usize,
) -> usize {
    unsafe {
        let buf = slice::from_raw_parts_mut(buffer, length);
        (*stream).read_into(buf)
    }
}

/// Write multiple bytes from a buffer.
#[no_mangle]
pub extern "C" fn memory_stream_write(
    stream: *mut MemoryStream,
    buffer: *const u8,
    length: usize,
) {
    unsafe {
        let buf = slice::from_raw_parts(buffer, length);
        (*stream).write(buf);
    }
}

/// Write a single byte.
#[no_mangle]
pub extern "C" fn memory_stream_write_byte(stream: *mut MemoryStream, value: u8) {
    unsafe {
        (*stream).write_byte(value);
    }
}

/// Write a 16-bit value.
#[no_mangle]
pub extern "C" fn memory_stream_write_short(stream: *mut MemoryStream, value: u16) {
    unsafe {
        (*stream).write_short(value);
    }
}

/// Write a 32-bit value.
#[no_mangle]
pub extern "C" fn memory_stream_write_dword(stream: *mut MemoryStream, value: u32) {
    unsafe {
        (*stream).write_dword(value);
    }
}

/// Write a 64-bit value.
#[no_mangle]
pub extern "C" fn memory_stream_write_qword(stream: *mut MemoryStream, value: u64) {
    unsafe {
        (*stream).write_qword(value);
    }
}

/// Read a byte at a specific address without changing offset.
#[no_mangle]
pub extern "C" fn memory_stream_read_byte_at(stream: *const MemoryStream, address: usize) -> u8 {
    unsafe {
        (*stream).read_byte_at(address)
    }
}

/// Write a byte at a specific address without changing offset.
#[no_mangle]
pub extern "C" fn memory_stream_write_byte_at(stream: *mut MemoryStream, address: usize, value: u8) {
    unsafe {
        (*stream).write_byte_at(address, value);
    }
}

/// Read a 16-bit value at a specific address.
#[no_mangle]
pub extern "C" fn memory_stream_read_short_at(stream: *const MemoryStream, address: usize) -> u16 {
    unsafe {
        (*stream).read_short_at(address)
    }
}

/// Write a 16-bit value at a specific address.
#[no_mangle]
pub extern "C" fn memory_stream_write_short_at(stream: *mut MemoryStream, address: usize, value: u16) {
    unsafe {
        (*stream).write_short_at(address, value);
    }
}

/// Read a 32-bit value at a specific address.
#[no_mangle]
pub extern "C" fn memory_stream_read_dword_at(stream: *const MemoryStream, address: usize) -> u32 {
    unsafe {
        (*stream).read_dword_at(address)
    }
}

/// Write a 32-bit value at a specific address.
#[no_mangle]
pub extern "C" fn memory_stream_write_dword_at(stream: *mut MemoryStream, address: usize, value: u32) {
    unsafe {
        (*stream).write_dword_at(address, value);
    }
}

/// Read a 64-bit value at a specific address.
#[no_mangle]
pub extern "C" fn memory_stream_read_qword_at(stream: *const MemoryStream, address: usize) -> u64 {
    unsafe {
        (*stream).read_qword_at(address)
    }
}

/// Write a 64-bit value at a specific address.
#[no_mangle]
pub extern "C" fn memory_stream_write_qword_at(stream: *mut MemoryStream, address: usize, value: u64) {
    unsafe {
        (*stream).write_qword_at(address, value);
    }
}

/// Copy data within the same memory stream.
#[no_mangle]
pub extern "C" fn memory_stream_copy_internal(
    stream: *mut MemoryStream,
    src_offset: usize,
    dest_offset: usize,
    size: usize,
) {
    unsafe {
        (*stream).copy_internal(src_offset, dest_offset, size);
    }
}

/// Copy data from an external buffer.
#[no_mangle]
pub extern "C" fn memory_stream_copy_from_external(
    stream: *mut MemoryStream,
    src: *const u8,
    src_len: usize,
    dest_offset: usize,
) {
    unsafe {
        let buf = slice::from_raw_parts(src, src_len);
        (*stream).copy_from_external(buf, dest_offset);
    }
}

/// Get a pointer to the internal buffer.
#[no_mangle]
pub extern "C" fn memory_stream_as_ptr(stream: *const MemoryStream) -> *const u8 {
    unsafe {
        (*stream).as_ptr()
    }
}

/// Get a mutable pointer to the internal buffer.
#[no_mangle]
pub extern "C" fn memory_stream_as_mut_ptr(stream: *mut MemoryStream) -> *mut u8 {
    unsafe {
        (*stream).as_mut_ptr()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_basic_operations() {
        let mut stream = MemoryStream::new(1024, 16 * 1024 * 1024, 256 * 1024 * 1024);

        // Test write and read
        stream.write_byte(0xAB);
        stream.set_offset(0);
        assert_eq!(stream.byte(), 0xAB);

        // Test short
        stream.set_offset(0);
        stream.write_short(0x1234);
        stream.set_offset(0);
        assert_eq!(stream.short(), 0x1234);

        // Test dword
        stream.set_offset(0);
        stream.write_dword(0xDEADBEEF);
        stream.set_offset(0);
        assert_eq!(stream.dword(), 0xDEADBEEF);
    }

    #[test]
    fn test_capacity_expansion() {
        let mut stream = MemoryStream::new(1024, 16 * 1024 * 1024, 256 * 1024 * 1024);

        // Write beyond initial capacity
        stream.set_offset(2048);
        stream.write_byte(0xFF);

        // Should have expanded
        assert!(stream.size() > 1024);
    }

    #[test]
    fn test_random_access_write_expands_and_persists() {
        let mut stream = MemoryStream::new(1024, 8192, 0);

        stream.write_byte_at(4096, 0xAA);

        assert_eq!(stream.read_byte_at(4096), 0xAA);
        assert!(stream.size() >= 4097);
    }
}
