use std::cmp;

use super::{MemoryStream, EXPANSION_CHUNK_SIZE, PAGE_SHIFT, PAGE_SIZE};

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

    /// Get a direct pointer to the internal memory buffer.
    /// This is useful for FFI when PHP needs direct memory access.
    pub fn as_ptr(&self) -> *const u8 {
        std::ptr::null()
    }

    /// Get a mutable pointer to the internal memory buffer.
    pub fn as_mut_ptr(&mut self) -> *mut u8 {
        std::ptr::null_mut()
    }
}
