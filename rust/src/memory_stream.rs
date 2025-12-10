//! High-performance memory stream implementation for x86 emulation.
//!
//! This module provides a Rust implementation of MemoryStream that can be called
//! from PHP via FFI. It uses a flat memory model with efficient byte-level access.

use std::slice;

/// Expansion chunk size (1MB)
const EXPANSION_CHUNK_SIZE: usize = 0x100000;

/// Memory stream structure with flat memory model.
/// Uses a single contiguous Vec<u8> for maximum performance.
#[repr(C)]
pub struct MemoryStream {
    /// Flat memory buffer
    data: Vec<u8>,
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
        let mut data = Vec::with_capacity(size);
        data.resize(size, 0);

        MemoryStream {
            data,
            offset: 0,
            size,
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

        // Resize the data vector
        self.data.resize(new_size, 0);
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

        let value = if self.offset < self.data.len() {
            self.data[self.offset]
        } else {
            0
        };
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

        let mut result = Vec::with_capacity(length);
        let actual_len = std::cmp::min(length, self.data.len().saturating_sub(self.offset));

        if actual_len > 0 {
            result.extend_from_slice(&self.data[self.offset..self.offset + actual_len]);
        }

        // Fill remaining with zeros if needed
        if actual_len < length {
            result.resize(length, 0);
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

        let actual_len = std::cmp::min(length, self.data.len().saturating_sub(self.offset));

        if actual_len > 0 {
            buffer[..actual_len].copy_from_slice(&self.data[self.offset..self.offset + actual_len]);
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
        let end_offset = self.offset + len;

        if end_offset >= self.size {
            let _ = self.ensure_capacity(end_offset);
        }

        if self.offset + len <= self.data.len() {
            self.data[self.offset..self.offset + len].copy_from_slice(value);
        }

        self.offset += len;
    }

    /// Write a single byte at current offset.
    #[inline(always)]
    pub fn write_byte(&mut self, value: u8) {
        if self.offset >= self.logical_max_memory_size() {
            return;
        }

        if self.offset >= self.size {
            let _ = self.ensure_capacity(self.offset);
        }

        if self.offset < self.data.len() {
            self.data[self.offset] = value;
        }
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
        if address < self.data.len() {
            self.data[address]
        } else {
            0
        }
    }

    /// Write a byte at a specific address without changing offset.
    #[inline(always)]
    pub fn write_byte_at(&mut self, address: usize, value: u8) {
        if address < self.data.len() {
            self.data[address] = value;
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

        // Ensure destination has capacity
        let dest_end = dest_offset + size;
        if dest_end >= self.size {
            let _ = self.ensure_capacity(dest_end);
        }

        // Use copy_within for overlapping regions
        if src_offset < self.data.len() && dest_offset < self.data.len() {
            let actual_size = std::cmp::min(
                size,
                std::cmp::min(
                    self.data.len() - src_offset,
                    self.data.len() - dest_offset,
                ),
            );
            self.data.copy_within(src_offset..src_offset + actual_size, dest_offset);
        }
    }

    /// Copy data from an external buffer to this memory.
    pub fn copy_from_external(&mut self, src: &[u8], dest_offset: usize) {
        let size = src.len();
        if size == 0 {
            return;
        }

        let dest_end = dest_offset + size;
        if dest_end >= self.size {
            let _ = self.ensure_capacity(dest_end);
        }

        if dest_offset < self.data.len() {
            let actual_size = std::cmp::min(size, self.data.len() - dest_offset);
            self.data[dest_offset..dest_offset + actual_size].copy_from_slice(&src[..actual_size]);
        }
    }

    /// Get a direct pointer to the internal memory buffer.
    /// This is useful for FFI when PHP needs direct memory access.
    pub fn as_ptr(&self) -> *const u8 {
        self.data.as_ptr()
    }

    /// Get a mutable pointer to the internal memory buffer.
    pub fn as_mut_ptr(&mut self) -> *mut u8 {
        self.data.as_mut_ptr()
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
}
