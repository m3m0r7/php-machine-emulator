use crate::memory_stream::MemoryStream;
use super::MemoryAccessor;


/// Create a new MemoryAccessor instance.
#[no_mangle]
pub extern "C" fn memory_accessor_new(memory: *mut MemoryStream) -> *mut MemoryAccessor {
    let accessor = Box::new(MemoryAccessor::new(memory));
    Box::into_raw(accessor)
}

/// Free a MemoryAccessor instance.
#[no_mangle]
pub extern "C" fn memory_accessor_free(accessor: *mut MemoryAccessor) {
    if !accessor.is_null() {
        unsafe {
            let _ = Box::from_raw(accessor);
        }
    }
}

/// Allocate a register or memory range.
#[no_mangle]
pub extern "C" fn memory_accessor_allocate(
    accessor: *mut MemoryAccessor,
    address: usize,
    size: usize,
    safe: bool,
) -> bool {
    unsafe { (*accessor).allocate(address, size, safe) }
}

/// Fetch a register value.
#[no_mangle]
pub extern "C" fn memory_accessor_fetch(accessor: *const MemoryAccessor, address: usize) -> i64 {
    unsafe { (*accessor).fetch(address) }
}

/// Fetch a register value with size.
#[no_mangle]
pub extern "C" fn memory_accessor_fetch_by_size(
    accessor: *const MemoryAccessor,
    address: usize,
    size: u32,
) -> i64 {
    unsafe { (*accessor).fetch_by_size(address, size) }
}

/// Try to fetch a register value.
#[no_mangle]
pub extern "C" fn memory_accessor_try_to_fetch(accessor: *const MemoryAccessor, address: usize) -> i64 {
    unsafe { (*accessor).try_to_fetch(address) }
}

/// Write a 16-bit value.
#[no_mangle]
pub extern "C" fn memory_accessor_write_16bit(accessor: *mut MemoryAccessor, address: usize, value: i64) {
    unsafe { (*accessor).write_16bit(address, value) }
}

/// Write a value by size.
#[no_mangle]
pub extern "C" fn memory_accessor_write_by_size(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: i64,
    size: u32,
) {
    unsafe { (*accessor).write_by_size(address, value, size) }
}

/// Write to high bit.
#[no_mangle]
pub extern "C" fn memory_accessor_write_to_high_bit(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: i64,
) {
    unsafe { (*accessor).write_to_high_bit(address, value) }
}

/// Write to low bit.
#[no_mangle]
pub extern "C" fn memory_accessor_write_to_low_bit(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: i64,
) {
    unsafe { (*accessor).write_to_low_bit(address, value) }
}

/// Update flags.
#[no_mangle]
pub extern "C" fn memory_accessor_update_flags(accessor: *mut MemoryAccessor, value: i64, size: u32) {
    unsafe { (*accessor).update_flags(value, size) }
}

/// Increment a register.
#[no_mangle]
pub extern "C" fn memory_accessor_increment(accessor: *mut MemoryAccessor, address: usize) {
    unsafe { (*accessor).increment(address) }
}

/// Decrement a register.
#[no_mangle]
pub extern "C" fn memory_accessor_decrement(accessor: *mut MemoryAccessor, address: usize) {
    unsafe { (*accessor).decrement(address) }
}

/// Add to a register.
#[no_mangle]
pub extern "C" fn memory_accessor_add(accessor: *mut MemoryAccessor, address: usize, value: i64) {
    unsafe { (*accessor).add(address, value) }
}

/// Subtract from a register.
#[no_mangle]
pub extern "C" fn memory_accessor_sub(accessor: *mut MemoryAccessor, address: usize, value: i64) {
    unsafe { (*accessor).sub(address, value) }
}

// Flag getters
#[no_mangle]
pub extern "C" fn memory_accessor_zero_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).zero_flag() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_sign_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).sign_flag() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_overflow_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).overflow_flag() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_carry_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).carry_flag() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_parity_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).parity_flag() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_auxiliary_carry_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).auxiliary_carry_flag() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_direction_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).direction_flag() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_interrupt_flag(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).interrupt_flag() }
}

// Flag setters
#[no_mangle]
pub extern "C" fn memory_accessor_set_zero_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_zero_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_sign_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_sign_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_overflow_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_overflow_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_carry_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_carry_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_parity_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_parity_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_auxiliary_carry_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_auxiliary_carry_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_direction_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_direction_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_interrupt_flag(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_interrupt_flag(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_set_instruction_fetch(accessor: *mut MemoryAccessor, value: bool) {
    unsafe { (*accessor).set_instruction_fetch(value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_instruction_fetch(accessor: *const MemoryAccessor) -> bool {
    unsafe { (*accessor).instruction_fetch() }
}

// Control register operations
#[no_mangle]
pub extern "C" fn memory_accessor_read_control_register(
    accessor: *const MemoryAccessor,
    index: usize,
) -> i64 {
    unsafe { (*accessor).read_control_register(index) as i64 }
}

#[no_mangle]
pub extern "C" fn memory_accessor_write_control_register(
    accessor: *mut MemoryAccessor,
    index: usize,
    value: i64,
) {
    unsafe { (*accessor).write_control_register(index, value as u64) }
}

// EFER operations
#[no_mangle]
pub extern "C" fn memory_accessor_read_efer(accessor: *const MemoryAccessor) -> u64 {
    unsafe { (*accessor).read_efer() }
}

#[no_mangle]
pub extern "C" fn memory_accessor_write_efer(accessor: *mut MemoryAccessor, value: u64) {
    unsafe { (*accessor).write_efer(value) }
}

// Memory operations
#[no_mangle]
pub extern "C" fn memory_accessor_read_from_memory(accessor: *const MemoryAccessor, address: usize) -> u8 {
    unsafe { (*accessor).read_from_memory(address) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_write_to_memory(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: u8,
) {
    unsafe { (*accessor).write_to_memory(address, value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_read_raw_byte(accessor: *const MemoryAccessor, address: usize) -> u8 {
    unsafe { (*accessor).read_raw_byte(address) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_write_raw_byte(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: u8,
) {
    unsafe { (*accessor).write_raw_byte(address, value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_read_physical_32(accessor: *const MemoryAccessor, address: usize) -> u32 {
    unsafe { (*accessor).read_physical_32(address) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_write_physical_32(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: u32,
) {
    unsafe { (*accessor).write_physical_32(address, value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_read_physical_64(accessor: *const MemoryAccessor, address: usize) -> u64 {
    unsafe { (*accessor).read_physical_64(address) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_write_physical_64(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: u64,
) {
    unsafe { (*accessor).write_physical_64(address, value) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_read_physical_8(accessor: *const MemoryAccessor, address: usize) -> u8 {
    unsafe { (*accessor).read_physical_8(address) }
}

#[no_mangle]
pub extern "C" fn memory_accessor_read_physical_16(accessor: *const MemoryAccessor, address: usize) -> u16 {
    unsafe { (*accessor).read_physical_16(address) }
}

/// Translate linear address to physical address.
/// Returns physical address in low 32/64 bits.
/// If there's a page fault, returns error info packed as:
/// - result_physical: the faulting linear address
/// - result_error: (vector << 16) | error_code, or 0xFFFFFFFF for MMIO
#[no_mangle]
pub extern "C" fn memory_accessor_translate_linear(
    accessor: *mut MemoryAccessor,
    linear: u64,
    is_write: bool,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
    result_physical: *mut u64,
    result_error: *mut u32,
) {
    unsafe {
        let (phys, err) = (*accessor).translate_linear(linear, is_write, is_user, paging_enabled, linear_mask);
        *result_physical = phys;
        *result_error = err;
    }
}

/// Check if address is in MMIO range.
#[no_mangle]
pub extern "C" fn memory_accessor_is_mmio_address(address: usize) -> bool {
    MemoryAccessor::is_mmio_address(address)
}

/// Read 8-bit memory with linear address translation.
/// Returns value in result_value, error in result_error.
/// If result_error == 0xFFFFFFFF, MMIO handling is needed.
#[no_mangle]
pub extern "C" fn memory_accessor_read_memory_8(
    accessor: *mut MemoryAccessor,
    linear: u64,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
    result_value: *mut u8,
    result_error: *mut u32,
) {
    unsafe {
        let (val, err) = (*accessor).read_memory_8(linear, is_user, paging_enabled, linear_mask);
        *result_value = val;
        *result_error = err;
    }
}

/// Read 16-bit memory with linear address translation.
#[no_mangle]
pub extern "C" fn memory_accessor_read_memory_16(
    accessor: *mut MemoryAccessor,
    linear: u64,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
    result_value: *mut u16,
    result_error: *mut u32,
) {
    unsafe {
        let (val, err) = (*accessor).read_memory_16(linear, is_user, paging_enabled, linear_mask);
        *result_value = val;
        *result_error = err;
    }
}

/// Read 32-bit memory with linear address translation.
#[no_mangle]
pub extern "C" fn memory_accessor_read_memory_32(
    accessor: *mut MemoryAccessor,
    linear: u64,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
    result_value: *mut u32,
    result_error: *mut u32,
) {
    unsafe {
        let (val, err) = (*accessor).read_memory_32(linear, is_user, paging_enabled, linear_mask);
        *result_value = val;
        *result_error = err;
    }
}

/// Read 64-bit memory with linear address translation.
#[no_mangle]
pub extern "C" fn memory_accessor_read_memory_64(
    accessor: *mut MemoryAccessor,
    linear: u64,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
    result_value: *mut u64,
    result_error: *mut u32,
) {
    unsafe {
        let (val, err) = (*accessor).read_memory_64(linear, is_user, paging_enabled, linear_mask);
        *result_value = val;
        *result_error = err;
    }
}

/// Write 8-bit memory with linear address translation.
/// Returns error code (0 on success, 0xFFFFFFFF for MMIO).
#[no_mangle]
pub extern "C" fn memory_accessor_write_memory_8(
    accessor: *mut MemoryAccessor,
    linear: u64,
    value: u8,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
) -> u32 {
    unsafe { (*accessor).write_memory_8(linear, value, is_user, paging_enabled, linear_mask) }
}

/// Write 16-bit memory with linear address translation.
/// Returns error code (0 on success, 0xFFFFFFFF for MMIO).
#[no_mangle]
pub extern "C" fn memory_accessor_write_memory_16(
    accessor: *mut MemoryAccessor,
    linear: u64,
    value: u16,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
) -> u32 {
    unsafe { (*accessor).write_memory_16(linear, value, is_user, paging_enabled, linear_mask) }
}

/// Write 32-bit memory with linear address translation.
/// Returns error code (0 on success, 0xFFFFFFFF for MMIO).
#[no_mangle]
pub extern "C" fn memory_accessor_write_memory_32(
    accessor: *mut MemoryAccessor,
    linear: u64,
    value: u32,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
) -> u32 {
    unsafe { (*accessor).write_memory_32(linear, value, is_user, paging_enabled, linear_mask) }
}

/// Write 64-bit memory with linear address translation.
/// Returns error code (0 on success, 0xFFFFFFFF for MMIO).
#[no_mangle]
pub extern "C" fn memory_accessor_write_memory_64(
    accessor: *mut MemoryAccessor,
    linear: u64,
    value: u64,
    is_user: bool,
    paging_enabled: bool,
    linear_mask: u64,
) -> u32 {
    unsafe { (*accessor).write_memory_64(linear, value, is_user, paging_enabled, linear_mask) }
}

/// Write 16-bit value to physical memory.
#[no_mangle]
pub extern "C" fn memory_accessor_write_physical_16(
    accessor: *mut MemoryAccessor,
    address: usize,
    value: u16,
) {
    unsafe { (*accessor).write_physical_16(address, value) }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_register_operations() {
        let mut memory = MemoryStream::new(1024, 16 * 1024 * 1024, 256 * 1024 * 1024);
        let mut accessor = MemoryAccessor::new(&mut memory as *mut MemoryStream);

        // Allocate EAX (address 0)
        accessor.allocate(0, 1, true);

        // Write and read
        accessor.write_by_size(0, 0x12345678, 32);
        assert_eq!(accessor.fetch_by_size(0, 32), 0x12345678);

        // Test 16-bit write preserves upper bits
        accessor.write_by_size(0, 0xABCD, 16);
        // In x86-64, 16-bit writes preserve upper bits
        assert_eq!(accessor.fetch_by_size(0, 16), 0xABCD);
    }

    #[test]
    fn test_flags() {
        let mut memory = MemoryStream::new(1024, 16 * 1024 * 1024, 256 * 1024 * 1024);
        let mut accessor = MemoryAccessor::new(&mut memory as *mut MemoryStream);

        // Test zero flag
        accessor.update_flags(0, 16);
        assert!(accessor.zero_flag());

        // Test sign flag
        accessor.update_flags(-1, 16);
        assert!(accessor.sign_flag());

        // Test parity flag
        accessor.update_flags(0xFF, 16);
        assert!(accessor.parity_flag()); // 8 ones = even
    }
}
