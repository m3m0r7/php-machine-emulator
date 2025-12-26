mod meta;
mod access;
mod copy;

#[cfg(test)]
mod tests {
    use crate::memory_stream::MemoryStream;

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
