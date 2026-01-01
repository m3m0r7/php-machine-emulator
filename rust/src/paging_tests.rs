use crate::{MemoryAccessor, MemoryStream};

fn make_accessor() -> (Box<MemoryStream>, MemoryAccessor) {
    // Keep memory reasonably small; paging structures live in low memory.
    // Use a Box to keep the MemoryStream address stable for the raw pointer stored in MemoryAccessor.
    let mut memory = Box::new(MemoryStream::new(0x20000, 0x20000, 0));
    let accessor = MemoryAccessor::new(memory.as_mut() as *mut MemoryStream);
    (memory, accessor)
}

#[test]
fn ia32e_translate_linear_maps_4k_page_and_sets_accessed_bits() {
    let (mut memory, mut acc) = make_accessor();

    // Page table layout:
    // CR3 -> PML4 @ 0x1000
    // PML4[0] -> PDPT @ 0x2000
    // PDPT[0] -> PD   @ 0x3000
    // PD[0]   -> PT   @ 0x4000
    let pml4 = 0x1000usize;
    let pdpt = 0x2000usize;
    let pd = 0x3000usize;
    let pt = 0x4000usize;

    // IA-32e enabled (EFER.LME=1) + PAE.
    acc.write_efer(1 << 8);
    acc.write_control_register(3, pml4 as u64);
    acc.write_control_register(4, 1 << 5);

    let flags = 0x001 | 0x002 | 0x004; // P | RW | US
    memory.write_qword_at(pml4 + 0 * 8, (pdpt as u64) | flags);
    memory.write_qword_at(pdpt + 0 * 8, (pd as u64) | flags);
    memory.write_qword_at(pd + 0 * 8, (pt as u64) | flags);

    // Map linear 0x00123000 -> physical 0x00123000 (identity).
    let linear: u64 = 0x0012_3000;
    let pt_index = ((linear >> 12) & 0x1FF) as usize;
    memory.write_qword_at(pt + pt_index * 8, (linear & 0xFFFF_F000) | flags);

    let (phys, err) = acc.translate_linear(linear, false, true, true, 0x0000_FFFF_FFFF_FFFF);
    assert_eq!(err, 0);
    assert_eq!(phys, linear);

    // Accessed bit should be set in PML4E, PDPTE, PDE, and PTE.
    let a = 1u64 << 5;
    assert_ne!(memory.read_qword_at(pml4) & a, 0);
    assert_ne!(memory.read_qword_at(pdpt) & a, 0);
    assert_ne!(memory.read_qword_at(pd) & a, 0);
    assert_ne!(memory.read_qword_at(pt + pt_index * 8) & a, 0);
}

#[test]
fn ia32e_translate_linear_user_violation_sets_pf_error_bits() {
    let (mut memory, mut acc) = make_accessor();

    let pml4 = 0x1000usize;
    let pdpt = 0x2000usize;
    let pd = 0x3000usize;
    let pt = 0x4000usize;

    acc.write_efer(1 << 8);
    acc.write_control_register(3, pml4 as u64);
    acc.write_control_register(4, 1 << 5);

    let flags_su_rw = 0x001 | 0x002; // P | RW (US=0)
    let flags_us_rw = flags_su_rw | 0x004; // add US

    // Use a canonical high address to ensure CR2 is written with sign-extension.
    let linear: u64 = 0xFFFF_8000_0000_4000;
    let pml4_index = ((linear >> 39) & 0x1FF) as usize;

    memory.write_qword_at(pml4 + pml4_index * 8, (pdpt as u64) | flags_us_rw);
    memory.write_qword_at(pdpt + 0 * 8, (pd as u64) | flags_us_rw);
    memory.write_qword_at(pd + 0 * 8, (pt as u64) | flags_us_rw);

    let pt_index = ((linear >> 12) & 0x1FF) as usize;
    // PTE is supervisor-only (US=0).
    memory.write_qword_at(pt + pt_index * 8, (linear & 0xFFFF_F000) | flags_su_rw);

    let (_phys, err) = acc.translate_linear(linear, false, true, true, 0x0000_FFFF_FFFF_FFFF);
    assert_eq!(acc.read_control_register(2), linear);

    // #PF vector (0x0E) plus error code: P=1, U/S=1, W/R=0 => 0b101 = 0x5.
    assert_eq!(err, (0x0E << 16) | 0x5);
}
