use std::cmp;

use super::super::MemoryStream;

impl MemoryStream {
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
}
