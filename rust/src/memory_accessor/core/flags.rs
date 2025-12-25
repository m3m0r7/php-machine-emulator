use super::MemoryAccessor;

impl MemoryAccessor {
    /// Update CPU flags based on a value.
    #[inline(always)]
    pub fn update_flags(&mut self, value: i64, size: u32) {
        // Operand sizes are 8/16/32/64. For 64-bit values, `value` is already a signed i64
        // representing the full 64-bit result, so masking and signed-range checks are unnecessary.
        if size >= 64 {
            self.zero_flag = value == 0;
            self.sign_flag = value < 0;
            // OF cannot be derived from the result alone for most operations.
            self.overflow_flag = false;
            self.parity_flag = ((value & 0xFF) as u8).count_ones() % 2 == 0;
            return;
        }

        let mask = (1i64 << size) - 1;
        let masked = value & mask;

        self.zero_flag = masked == 0;
        self.sign_flag = (masked & (1i64 << (size - 1))) != 0;

        // Overflow flag calculation (best-effort; many instructions override OF explicitly)
        let signed_min = -(1i64 << (size - 1));
        let signed_max = (1i64 << (size - 1)) - 1;
        self.overflow_flag = value < signed_min || value > signed_max;

        // Parity flag (count of 1 bits in low byte)
        self.parity_flag = ((masked & 0xFF) as u8).count_ones() % 2 == 0;
    }

    // Flag getters
    #[inline(always)]
    pub fn zero_flag(&self) -> bool {
        self.zero_flag
    }

    #[inline(always)]
    pub fn sign_flag(&self) -> bool {
        self.sign_flag
    }

    #[inline(always)]
    pub fn overflow_flag(&self) -> bool {
        self.overflow_flag
    }

    #[inline(always)]
    pub fn carry_flag(&self) -> bool {
        self.carry_flag
    }

    #[inline(always)]
    pub fn parity_flag(&self) -> bool {
        self.parity_flag
    }

    #[inline(always)]
    pub fn auxiliary_carry_flag(&self) -> bool {
        self.auxiliary_carry_flag
    }

    #[inline(always)]
    pub fn direction_flag(&self) -> bool {
        self.direction_flag
    }

    #[inline(always)]
    pub fn interrupt_flag(&self) -> bool {
        self.interrupt_flag
    }

    // Flag setters
    #[inline(always)]
    pub fn set_zero_flag(&mut self, value: bool) {
        self.zero_flag = value;
    }

    #[inline(always)]
    pub fn set_sign_flag(&mut self, value: bool) {
        self.sign_flag = value;
    }

    #[inline(always)]
    pub fn set_overflow_flag(&mut self, value: bool) {
        self.overflow_flag = value;
    }

    #[inline(always)]
    pub fn set_carry_flag(&mut self, value: bool) {
        self.carry_flag = value;
    }

    #[inline(always)]
    pub fn set_parity_flag(&mut self, value: bool) {
        self.parity_flag = value;
    }

    #[inline(always)]
    pub fn set_auxiliary_carry_flag(&mut self, value: bool) {
        self.auxiliary_carry_flag = value;
    }

    #[inline(always)]
    pub fn set_direction_flag(&mut self, value: bool) {
        self.direction_flag = value;
    }

    #[inline(always)]
    pub fn set_interrupt_flag(&mut self, value: bool) {
        self.interrupt_flag = value;
    }

    #[inline(always)]
    pub fn set_instruction_fetch(&mut self, value: bool) {
        self.instruction_fetch = value;
    }

    #[inline(always)]
    pub fn instruction_fetch(&self) -> bool {
        self.instruction_fetch
    }
}
