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
//! To keep bootloader-heavy guests practical, we use a sparse page-backed model:
//! - Unallocated pages read as zero
//! - Pages are allocated (zeroed) only on first write
//! - The logical address space remains `physical_max_memory_size + swap_size`

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

mod core;
mod ffi;

pub use ffi::*;
