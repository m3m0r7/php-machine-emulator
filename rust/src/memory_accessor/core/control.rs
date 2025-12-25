use super::MemoryAccessor;

impl MemoryAccessor {
    // Control register operations
    #[inline(always)]
    pub fn read_control_register(&self, index: usize) -> u64 {
        if index < self.control_registers.len() {
            self.control_registers[index]
        } else {
            0
        }
    }

    #[inline(always)]
    pub fn write_control_register(&mut self, index: usize, value: u64) {
        if index < self.control_registers.len() {
            self.control_registers[index] = value;
        }
    }

    // EFER operations
    #[inline(always)]
    pub fn read_efer(&self) -> u64 {
        self.efer
    }

    #[inline(always)]
    pub fn write_efer(&mut self, value: u64) {
        self.efer = value;
    }
}
