use super::MemoryAccessor;

impl MemoryAccessor {
    /// Translate linear address to physical address through paging.
    /// Returns: (physical_address, error_code) where error_code is 0 on success,
    /// or a packed value: (vector << 16) | error_code on page fault.
    /// If MMIO is detected, returns (address, 0xFFFFFFFF) to signal PHP should handle it.
    pub fn translate_linear(
        &mut self,
        linear: u64,
        is_write: bool,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> (u64, u32) {
        let linear = linear & linear_mask;

        if !paging_enabled {
            return (linear, 0);
        }

        let cr4 = self.control_registers[4];
        let pse = (cr4 & (1 << 4)) != 0;
        let pae = (cr4 & (1 << 5)) != 0;
        let lme = (self.efer & (1 << 8)) != 0;

        let (physical, err) = if pae {
            if lme {
                self.translate_linear_ia32e(linear, is_write, is_user)
            } else {
                self.translate_linear_pae(linear, is_write, is_user)
            }
        } else {
            self.translate_linear_32(linear, is_write, is_user, pse)
        };

        // On a page fault, CR2 is set to the faulting linear address.
        if err != 0 {
            let cr2 = if pae && lme {
                // Canonicalize 48-bit linear address (sign-extend bit 47).
                if (linear & (1u64 << 47)) != 0 {
                    linear | 0xFFFF_0000_0000_0000
                } else {
                    linear
                }
            } else {
                // Non-IA32e: CR2 is 32-bit.
                linear & 0xFFFF_FFFF
            };
            self.control_registers[2] = cr2;
        }

        (physical, err)
    }

    /// 32-bit paging translation.
    fn translate_linear_32(
        &mut self,
        linear: u64,
        is_write: bool,
        is_user: bool,
        pse: bool,
    ) -> (u64, u32) {
        let cr3 = (self.control_registers[3] & 0xFFFFF000) as usize;
        let linear = linear as usize;
        let dir_index = (linear >> 22) & 0x3FF;
        let table_index = (linear >> 12) & 0x3FF;
        let offset = linear & 0xFFF;

        let pde_addr = (cr3 + (dir_index * 4)) & 0xFFFFFFFF;
        let pde = self.read_physical_32(pde_addr) as u64;

        // Check PDE present
        if (pde & 0x1) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | (if is_user { 0b100 } else { 0 });
            return (linear as u64, (0x0E << 16) | err);
        }

        // Check reserved bits
        if (pde & 0xFFFFFF000) == 0 {
            let err = 0x08 | (if self.instruction_fetch { 0x10 } else { 0 });
            return (linear as u64, (0x0E << 16) | err);
        }

        // Check user access
        if is_user && (pde & 0x4) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | 0b100 | 0b1;
            return (linear as u64, (0x0E << 16) | err);
        }

        // Check write access
        if is_write && (pde & 0x2) == 0 {
            let err = 0b10 | (if is_user { 0b100 } else { 0 }) | 0b1;
            return (linear as u64, (0x0E << 16) | err);
        }

        // Handle 4MB page (PSE)
        let is_4m = pse && ((pde & (1 << 7)) != 0);
        if is_4m {
            let base = (pde & 0xFFC00000) as usize;
            let mut pde = pde;
            pde |= 0x20; // Set accessed
            if is_write {
                pde |= 0x40; // Set dirty
            }
            self.write_physical_32(pde_addr, pde as u32);
            let phys = ((base + (linear & 0x3FFFFF)) & 0xFFFFFFFF) as u64;
            return (phys, 0);
        }

        // Read PTE
        let pte_addr = ((pde & 0xFFFFF000) as usize + (table_index * 4)) & 0xFFFFFFFF;
        let pte = self.read_physical_32(pte_addr) as u64;

        // Check PTE present
        if (pte & 0x1) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | (if is_user { 0b100 } else { 0 });
            return (linear as u64, (0x0E << 16) | err);
        }

        // Check reserved bits
        if (pte & 0xFFFFFF000) == 0 {
            let err = 0x08 | (if self.instruction_fetch { 0x10 } else { 0 });
            return (linear as u64, (0x0E << 16) | err);
        }

        // Check user access
        if is_user && (pte & 0x4) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | 0b100 | 0b1;
            return (linear as u64, (0x0E << 16) | err);
        }

        // Check write access
        if is_write && (pte & 0x2) == 0 {
            let err = 0b10 | (if is_user { 0b100 } else { 0 }) | 0b1;
            return (linear as u64, (0x0E << 16) | err);
        }

        // Set accessed/dirty bits
        let mut pde = pde;
        pde |= 0x20;
        self.write_physical_32(pde_addr, pde as u32);

        let mut pte = pte;
        pte |= 0x20;
        if is_write {
            pte |= 0x40;
        }
        self.write_physical_32(pte_addr, pte as u32);

        let phys = (((pte & 0xFFFFF000) as usize + offset) & 0xFFFFFFFF) as u64;
        (phys, 0)
    }

    /// PAE paging translation.
    fn translate_linear_pae(
        &mut self,
        linear: u64,
        is_write: bool,
        is_user: bool,
    ) -> (u64, u32) {
        let cr3 = (self.control_registers[3] & 0xFFFFF000) as usize;
        let linear_usize = linear as usize;
        let pdp_index = (linear_usize >> 30) & 0x3;
        let dir_index = (linear_usize >> 21) & 0x1FF;
        let table_index = (linear_usize >> 12) & 0x1FF;
        let offset = linear_usize & 0xFFF;

        // Read PDPTE
        let pdpte_addr = (cr3 + (pdp_index * 8)) & 0xFFFFFFFF;
        let pdpte = self.read_physical_64(pdpte_addr);

        // Check PDPTE present
        if (pdpte & 0x1) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | (if is_user { 0b100 } else { 0 });
            return (linear, (0x0E << 16) | err);
        }

        // Check user access
        if is_user && (pdpte & 0x4) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | 0b100 | 0b1;
            return (linear, (0x0E << 16) | err);
        }

        // Check write access
        if is_write && (pdpte & 0x2) == 0 {
            let err = 0b10 | (if is_user { 0b100 } else { 0 }) | 0b1;
            return (linear, (0x0E << 16) | err);
        }

        // Mark PDPTE accessed
        self.write_physical_64(pdpte_addr, pdpte | (1 << 5));

        // Read PDE
        let pde_addr = (((pdpte & 0xFFFFFF000) as usize) + (dir_index * 8)) & 0xFFFFFFFF;
        let pde = self.read_physical_64(pde_addr);

        // Check PDE present
        if (pde & 0x1) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | (if is_user { 0b100 } else { 0 });
            return (linear, (0x0E << 16) | err);
        }

        let is_large = (pde & (1 << 7)) != 0;

        // Check user access
        if is_user && (pde & 0x4) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | 0b100 | 0b1;
            return (linear, (0x0E << 16) | err);
        }

        // Check write access
        if is_write && (pde & 0x2) == 0 {
            let err = 0b10 | (if is_user { 0b100 } else { 0 }) | 0b1;
            return (linear, (0x0E << 16) | err);
        }

        // Handle 2MB large page
        if is_large {
            let mut pde = pde;
            pde |= 0x20;
            if is_write {
                pde |= 0x40;
            }
            self.write_physical_64(pde_addr, pde);
            let base = (pde & 0xFFE00000) as usize;
            let phys = ((base + (linear_usize & 0x1FFFFF)) & 0xFFFFFFFF) as u64;
            return (phys, 0);
        }

        // Read PTE
        let pte_addr = (((pde & 0xFFFFFF000) as usize) + (table_index * 8)) & 0xFFFFFFFF;
        let pte = self.read_physical_64(pte_addr);

        // Check PTE present
        if (pte & 0x1) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | (if is_user { 0b100 } else { 0 });
            return (linear, (0x0E << 16) | err);
        }

        // Check user access
        if is_user && (pte & 0x4) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | 0b100 | 0b1;
            return (linear, (0x0E << 16) | err);
        }

        // Check write access
        if is_write && (pte & 0x2) == 0 {
            let err = 0b10 | (if is_user { 0b100 } else { 0 }) | 0b1;
            return (linear, (0x0E << 16) | err);
        }

        // Set accessed/dirty bits
        self.write_physical_64(pde_addr, pde | 0x20);
        let mut pte_updated = pte | 0x20;
        if is_write {
            pte_updated |= 0x40;
        }
        self.write_physical_64(pte_addr, pte_updated);

        let phys = ((pte & 0xFFFFFF000) as usize + offset) as u64;
        (phys & 0xFFFFFFFF, 0)
    }

    /// IA-32e (long mode) 4-level paging translation (PML4).
    ///
    /// This is selected when CR4.PAE=1, paging_enabled=true, and EFER.LME=1.
    /// Physical addresses are treated as 32-bit (best-effort) to match the current MemoryStream model.
    fn translate_linear_ia32e(
        &mut self,
        linear: u64,
        is_write: bool,
        is_user: bool,
    ) -> (u64, u32) {
        let cr3 = (self.control_registers[3] & 0xFFFFF000) as usize;
        let linear_usize = linear as usize;

        let pml4_index = ((linear >> 39) & 0x1FF) as usize;
        let pdpt_index = ((linear >> 30) & 0x1FF) as usize;
        let dir_index = ((linear >> 21) & 0x1FF) as usize;
        let table_index = ((linear >> 12) & 0x1FF) as usize;
        let offset = linear_usize & 0xFFF;

        // Read PML4E
        let pml4e_addr = (cr3 + (pml4_index * 8)) & 0xFFFFFFFF;
        let pml4e = self.read_physical_64(pml4e_addr);

        if (pml4e & 0x1) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | (if is_user { 0b100 } else { 0 });
            return (linear, (0x0E << 16) | err);
        }
        if is_user && (pml4e & 0x4) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | 0b100 | 0b1;
            return (linear, (0x0E << 16) | err);
        }
        if is_write && (pml4e & 0x2) == 0 {
            let err = 0b10 | (if is_user { 0b100 } else { 0 }) | 0b1;
            return (linear, (0x0E << 16) | err);
        }
        // Mark PML4E accessed
        self.write_physical_64(pml4e_addr, pml4e | (1 << 5));

        // Read PDPTE
        let pdpte_base = (pml4e & 0xFFFFFF000) as usize;
        let pdpte_addr = (pdpte_base + (pdpt_index * 8)) & 0xFFFFFFFF;
        let pdpte = self.read_physical_64(pdpte_addr);

        if (pdpte & 0x1) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | (if is_user { 0b100 } else { 0 });
            return (linear, (0x0E << 16) | err);
        }
        if is_user && (pdpte & 0x4) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | 0b100 | 0b1;
            return (linear, (0x0E << 16) | err);
        }
        if is_write && (pdpte & 0x2) == 0 {
            let err = 0b10 | (if is_user { 0b100 } else { 0 }) | 0b1;
            return (linear, (0x0E << 16) | err);
        }
        // Mark PDPTE accessed
        self.write_physical_64(pdpte_addr, pdpte | (1 << 5));

        // 1GB large page (PS)
        if (pdpte & (1 << 7)) != 0 {
            let mut pdpte_upd = pdpte | 0x20;
            if is_write {
                pdpte_upd |= 0x40;
            }
            self.write_physical_64(pdpte_addr, pdpte_upd);

            // Base is 1GB-aligned; keep within 32-bit physical space.
            let base = (pdpte & 0x000FFFFF_C0000000) as usize;
            let phys = ((base + (linear_usize & 0x3FFFFFFF)) & 0xFFFFFFFF) as u64;
            return (phys, 0);
        }

        // Read PDE
        let pde_addr = (((pdpte & 0xFFFFFF000) as usize) + (dir_index * 8)) & 0xFFFFFFFF;
        let pde = self.read_physical_64(pde_addr);

        if (pde & 0x1) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | (if is_user { 0b100 } else { 0 });
            return (linear, (0x0E << 16) | err);
        }
        if is_user && (pde & 0x4) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | 0b100 | 0b1;
            return (linear, (0x0E << 16) | err);
        }
        if is_write && (pde & 0x2) == 0 {
            let err = 0b10 | (if is_user { 0b100 } else { 0 }) | 0b1;
            return (linear, (0x0E << 16) | err);
        }

        // 2MB large page (PS)
        if (pde & (1 << 7)) != 0 {
            let mut pde_upd = pde | 0x20;
            if is_write {
                pde_upd |= 0x40;
            }
            self.write_physical_64(pde_addr, pde_upd);

            let base = (pde & 0xFFE00000) as usize;
            let phys = ((base + (linear_usize & 0x1FFFFF)) & 0xFFFFFFFF) as u64;
            return (phys, 0);
        }

        // Read PTE
        let pte_addr = (((pde & 0xFFFFFF000) as usize) + (table_index * 8)) & 0xFFFFFFFF;
        let pte = self.read_physical_64(pte_addr);

        if (pte & 0x1) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | (if is_user { 0b100 } else { 0 });
            return (linear, (0x0E << 16) | err);
        }
        if is_user && (pte & 0x4) == 0 {
            let err = (if is_write { 0b10 } else { 0 }) | 0b100 | 0b1;
            return (linear, (0x0E << 16) | err);
        }
        if is_write && (pte & 0x2) == 0 {
            let err = 0b10 | (if is_user { 0b100 } else { 0 }) | 0b1;
            return (linear, (0x0E << 16) | err);
        }

        // Set accessed/dirty bits
        self.write_physical_64(pde_addr, pde | 0x20);
        let mut pte_updated = pte | 0x20;
        if is_write {
            pte_updated |= 0x40;
        }
        self.write_physical_64(pte_addr, pte_updated);

        let phys = ((pte & 0xFFFFFF000) as usize + offset) as u64;
        (phys & 0xFFFFFFFF, 0)
    }

    /// Read memory with linear address translation.
    /// Returns (value, error_code). error_code is 0 on success.
    pub fn read_memory_8(
        &mut self,
        linear: u64,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> (u8, u32) {
        let (physical, err) = self.translate_linear(linear, false, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return (0, err);
        }
        if Self::is_mmio_address(physical as usize) {
            return (0, 0xFFFFFFFF); // Signal PHP to handle MMIO
        }
        (self.read_physical_8(physical as usize), 0)
    }

    /// Read 16-bit memory with linear address translation.
    pub fn read_memory_16(
        &mut self,
        linear: u64,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> (u16, u32) {
        let (physical, err) = self.translate_linear(linear, false, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return (0, err);
        }
        if Self::is_mmio_address(physical as usize) {
            return (0, 0xFFFFFFFF);
        }
        (self.read_physical_16(physical as usize), 0)
    }

    /// Read 32-bit memory with linear address translation.
    pub fn read_memory_32(
        &mut self,
        linear: u64,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> (u32, u32) {
        let (physical, err) = self.translate_linear(linear, false, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return (0, err);
        }
        if Self::is_mmio_address(physical as usize) {
            return (0, 0xFFFFFFFF);
        }
        (self.read_physical_32(physical as usize), 0)
    }

    /// Read 64-bit memory with linear address translation.
    pub fn read_memory_64(
        &mut self,
        linear: u64,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> (u64, u32) {
        let (physical, err) = self.translate_linear(linear, false, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return (0, err);
        }
        if Self::is_mmio_address(physical as usize) {
            return (0, 0xFFFFFFFF);
        }
        (self.read_physical_64(physical as usize), 0)
    }

    /// Write 8-bit memory with linear address translation.
    /// Returns error_code (0 on success).
    pub fn write_memory_8(
        &mut self,
        linear: u64,
        value: u8,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> u32 {
        let (physical, err) = self.translate_linear(linear, true, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return err;
        }
        if Self::is_mmio_address(physical as usize) {
            return 0xFFFFFFFF; // Signal PHP to handle MMIO
        }
        self.write_raw_byte(physical as usize, value);
        0
    }

    /// Write 16-bit memory with linear address translation.
    /// Returns error_code (0 on success).
    pub fn write_memory_16(
        &mut self,
        linear: u64,
        value: u16,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> u32 {
        let (physical, err) = self.translate_linear(linear, true, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return err;
        }
        if Self::is_mmio_address(physical as usize) {
            return 0xFFFFFFFF;
        }
        // Write little-endian
        self.write_raw_byte(physical as usize, (value & 0xFF) as u8);
        self.write_raw_byte((physical + 1) as usize, ((value >> 8) & 0xFF) as u8);
        0
    }

    /// Write 32-bit memory with linear address translation.
    /// Returns error_code (0 on success).
    pub fn write_memory_32(
        &mut self,
        linear: u64,
        value: u32,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> u32 {
        let (physical, err) = self.translate_linear(linear, true, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return err;
        }
        if Self::is_mmio_address(physical as usize) {
            return 0xFFFFFFFF;
        }
        self.write_physical_32(physical as usize, value);
        0
    }

    /// Write 64-bit memory with linear address translation.
    /// Returns error_code (0 on success).
    pub fn write_memory_64(
        &mut self,
        linear: u64,
        value: u64,
        is_user: bool,
        paging_enabled: bool,
        linear_mask: u64,
    ) -> u32 {
        let (physical, err) = self.translate_linear(linear, true, is_user, paging_enabled, linear_mask);
        if err != 0 {
            return err;
        }
        if Self::is_mmio_address(physical as usize) {
            return 0xFFFFFFFF;
        }
        self.write_physical_64(physical as usize, value);
        0
    }

    /// Write 16-bit value to physical memory.
    #[inline(always)]
    pub fn write_physical_16(&mut self, address: usize, value: u16) {
        self.write_to_memory(address, (value & 0xFF) as u8);
        self.write_to_memory(address + 1, ((value >> 8) & 0xFF) as u8);
    }
}
