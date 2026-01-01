#![allow(clippy::missing_safety_doc)]

use super::MemoryStream;
use std::slice;


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
pub unsafe extern "C" fn memory_stream_free(stream: *mut MemoryStream) {
    if !stream.is_null() {
        unsafe {
            let _ = Box::from_raw(stream);
        }
    }
}

/// Get the logical max memory size.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_logical_max_memory_size(stream: *const MemoryStream) -> usize {
    unsafe {
        (*stream).logical_max_memory_size()
    }
}

/// Get the physical max memory size.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_physical_max_memory_size(stream: *const MemoryStream) -> usize {
    unsafe {
        (*stream).physical_max_memory_size()
    }
}

/// Get the swap size.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_swap_size(stream: *const MemoryStream) -> usize {
    unsafe {
        (*stream).swap_size()
    }
}

/// Get the current size.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_size(stream: *const MemoryStream) -> usize {
    unsafe {
        (*stream).size()
    }
}

/// Ensure capacity for the given offset.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_ensure_capacity(stream: *mut MemoryStream, required_offset: usize) -> bool {
    unsafe {
        (*stream).ensure_capacity(required_offset)
    }
}

/// Get current offset.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_offset(stream: *const MemoryStream) -> usize {
    unsafe {
        (*stream).offset()
    }
}

/// Set current offset.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_set_offset(stream: *mut MemoryStream, new_offset: usize) -> bool {
    unsafe {
        (*stream).set_offset(new_offset)
    }
}

/// Check if at EOF.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_is_eof(stream: *const MemoryStream) -> bool {
    unsafe {
        (*stream).is_eof()
    }
}

/// Read a single byte.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_byte(stream: *mut MemoryStream) -> u8 {
    unsafe {
        (*stream).byte()
    }
}

/// Read a signed byte.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_signed_byte(stream: *mut MemoryStream) -> i8 {
    unsafe {
        (*stream).signed_byte()
    }
}

/// Read a 16-bit value.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_short(stream: *mut MemoryStream) -> u16 {
    unsafe {
        (*stream).short()
    }
}

/// Read a 32-bit value.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_dword(stream: *mut MemoryStream) -> u32 {
    unsafe {
        (*stream).dword()
    }
}

/// Read a 64-bit value.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_qword(stream: *mut MemoryStream) -> u64 {
    unsafe {
        (*stream).qword()
    }
}

/// Read multiple bytes into a buffer.
/// Returns the number of bytes read.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_read(
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
pub unsafe extern "C" fn memory_stream_write(
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
pub unsafe extern "C" fn memory_stream_write_byte(stream: *mut MemoryStream, value: u8) {
    unsafe {
        (*stream).write_byte(value);
    }
}

/// Write a 16-bit value.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_write_short(stream: *mut MemoryStream, value: u16) {
    unsafe {
        (*stream).write_short(value);
    }
}

/// Write a 32-bit value.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_write_dword(stream: *mut MemoryStream, value: u32) {
    unsafe {
        (*stream).write_dword(value);
    }
}

/// Write a 64-bit value.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_write_qword(stream: *mut MemoryStream, value: u64) {
    unsafe {
        (*stream).write_qword(value);
    }
}

/// Read a byte at a specific address without changing offset.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_read_byte_at(stream: *const MemoryStream, address: usize) -> u8 {
    unsafe {
        (*stream).read_byte_at(address)
    }
}

/// Write a byte at a specific address without changing offset.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_write_byte_at(stream: *mut MemoryStream, address: usize, value: u8) {
    unsafe {
        (*stream).write_byte_at(address, value);
    }
}

/// Read a 16-bit value at a specific address.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_read_short_at(stream: *const MemoryStream, address: usize) -> u16 {
    unsafe {
        (*stream).read_short_at(address)
    }
}

/// Write a 16-bit value at a specific address.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_write_short_at(stream: *mut MemoryStream, address: usize, value: u16) {
    unsafe {
        (*stream).write_short_at(address, value);
    }
}

/// Read a 32-bit value at a specific address.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_read_dword_at(stream: *const MemoryStream, address: usize) -> u32 {
    unsafe {
        (*stream).read_dword_at(address)
    }
}

/// Write a 32-bit value at a specific address.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_write_dword_at(stream: *mut MemoryStream, address: usize, value: u32) {
    unsafe {
        (*stream).write_dword_at(address, value);
    }
}

/// Read a 64-bit value at a specific address.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_read_qword_at(stream: *const MemoryStream, address: usize) -> u64 {
    unsafe {
        (*stream).read_qword_at(address)
    }
}

/// Write a 64-bit value at a specific address.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_write_qword_at(stream: *mut MemoryStream, address: usize, value: u64) {
    unsafe {
        (*stream).write_qword_at(address, value);
    }
}

/// Copy data within the same memory stream.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_copy_internal(
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
pub unsafe extern "C" fn memory_stream_copy_from_external(
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
pub unsafe extern "C" fn memory_stream_as_ptr(stream: *const MemoryStream) -> *const u8 {
    unsafe {
        (*stream).as_ptr()
    }
}

/// Get a mutable pointer to the internal buffer.
#[no_mangle]
pub unsafe extern "C" fn memory_stream_as_mut_ptr(stream: *mut MemoryStream) -> *mut u8 {
    unsafe {
        (*stream).as_mut_ptr()
    }
}
