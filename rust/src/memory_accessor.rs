//! High-performance memory accessor implementation for x86 emulation.
//!
//! This module provides a Rust implementation of MemoryAccessor that manages
//! CPU registers, flags, and memory access for x86 emulation.

use crate::memory_stream::MemoryStream;

/// Register addresses layout:
/// 0-7:   GPRs (EAX-EDI / RAX-RDI)
/// 8-13:  Segment registers (ES, CS, SS, DS, FS, GS)
/// 14-15: Reserved
/// 16-23: Extended GPRs (R8-R15)
/// 24:    RIP
/// 25:    EDI_ON_MEMORY (special)
const MAX_REGISTER_ADDRESS: usize = 26;

/// MemoryAccessor structure for managing CPU registers and flags.
#[repr(C)]
pub struct MemoryAccessor {
    /// Register storage (64-bit values for GPRs, 16-bit for segment registers)
    registers: [i64; MAX_REGISTER_ADDRESS],
    /// Which registers are allocated
    registers_allocated: [bool; MAX_REGISTER_ADDRESS],

    /// CPU Flags
    zero_flag: bool,
    sign_flag: bool,
    overflow_flag: bool,
    carry_flag: bool,
    parity_flag: bool,
    auxiliary_carry_flag: bool,
    direction_flag: bool,
    interrupt_flag: bool,
    instruction_fetch: bool,

    /// Extended Feature Enable Register (EFER MSR)
    efer: u64,

    /// Control registers (CR0-CR8).
    ///
    /// Stored as 64-bit to preserve long mode semantics:
    /// - CR2 must hold the full 64-bit faulting linear address.
    /// - CR3/CR4 are conceptually 64-bit in IA-32e.
    control_registers: [u64; 9],

    /// Pointer to the memory stream (owned by PHP, just referenced here)
    memory: *mut MemoryStream,
}

mod core;
mod paging;
mod ffi;

pub use ffi::*;
