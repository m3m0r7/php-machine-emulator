use std::ffi::CStr;
use std::os::raw::c_char;
use std::ptr;

fn make_u64(low: u32, high: u32) -> u64 {
    ((high as u64) << 32) | (low as u64)
}

fn write_u64_parts(value: u64, out_low: *mut u32, out_high: *mut u32) {
    unsafe {
        if !out_low.is_null() {
            *out_low = value as u32;
        }
        if !out_high.is_null() {
            *out_high = (value >> 32) as u32;
        }
    }
}

#[no_mangle]
pub extern "C" fn uint64_from_decimal(
    value: *const c_char,
    out_low: *mut u32,
    out_high: *mut u32,
) -> bool {
    if value.is_null() || out_low.is_null() || out_high.is_null() {
        return false;
    }

    let s = unsafe { CStr::from_ptr(value) };
    let s = match s.to_str() {
        Ok(v) => v.trim(),
        Err(_) => return false,
    };
    if s.is_empty() {
        return false;
    }

    let parsed = if let Some(hex) = s.strip_prefix("0x").or_else(|| s.strip_prefix("0X")) {
        u64::from_str_radix(hex, 16)
    } else {
        s.parse::<u64>()
    };

    let value = match parsed {
        Ok(v) => v,
        Err(_) => return false,
    };

    write_u64_parts(value, out_low, out_high);
    true
}

#[no_mangle]
pub extern "C" fn uint64_to_i64(low: u32, high: u32) -> i64 {
    make_u64(low, high) as i64
}

#[no_mangle]
pub extern "C" fn uint64_to_decimal(
    low: u32,
    high: u32,
    buffer: *mut c_char,
    buffer_len: usize,
) -> bool {
    if buffer.is_null() || buffer_len == 0 {
        return false;
    }

    let value = make_u64(low, high);
    let s = value.to_string();
    let bytes = s.as_bytes();
    if bytes.len() + 1 > buffer_len {
        return false;
    }

    unsafe {
        ptr::copy_nonoverlapping(bytes.as_ptr(), buffer as *mut u8, bytes.len());
        *buffer.add(bytes.len()) = 0;
    }

    true
}

#[no_mangle]
pub extern "C" fn uint64_add(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
    out_low: *mut u32,
    out_high: *mut u32,
) {
    let left = make_u64(left_low, left_high);
    let right = make_u64(right_low, right_high);
    let result = left.wrapping_add(right);
    write_u64_parts(result, out_low, out_high);
}

#[no_mangle]
pub extern "C" fn uint64_sub(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
    out_low: *mut u32,
    out_high: *mut u32,
) {
    let left = make_u64(left_low, left_high);
    let right = make_u64(right_low, right_high);
    let result = left.wrapping_sub(right);
    write_u64_parts(result, out_low, out_high);
}

#[no_mangle]
pub extern "C" fn uint64_mul(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
    out_low: *mut u32,
    out_high: *mut u32,
) {
    let left = make_u64(left_low, left_high);
    let right = make_u64(right_low, right_high);
    let result = left.wrapping_mul(right);
    write_u64_parts(result, out_low, out_high);
}

#[no_mangle]
pub extern "C" fn uint64_div(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
    out_low: *mut u32,
    out_high: *mut u32,
) -> bool {
    let left = make_u64(left_low, left_high);
    let right = make_u64(right_low, right_high);
    if right == 0 {
        return false;
    }
    let result = left / right;
    write_u64_parts(result, out_low, out_high);
    true
}

#[no_mangle]
pub extern "C" fn uint64_mod(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
    out_low: *mut u32,
    out_high: *mut u32,
) -> bool {
    let left = make_u64(left_low, left_high);
    let right = make_u64(right_low, right_high);
    if right == 0 {
        return false;
    }
    let result = left % right;
    write_u64_parts(result, out_low, out_high);
    true
}

#[no_mangle]
pub extern "C" fn uint64_and(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
    out_low: *mut u32,
    out_high: *mut u32,
) {
    let left = make_u64(left_low, left_high);
    let right = make_u64(right_low, right_high);
    write_u64_parts(left & right, out_low, out_high);
}

#[no_mangle]
pub extern "C" fn uint64_or(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
    out_low: *mut u32,
    out_high: *mut u32,
) {
    let left = make_u64(left_low, left_high);
    let right = make_u64(right_low, right_high);
    write_u64_parts(left | right, out_low, out_high);
}

#[no_mangle]
pub extern "C" fn uint64_xor(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
    out_low: *mut u32,
    out_high: *mut u32,
) {
    let left = make_u64(left_low, left_high);
    let right = make_u64(right_low, right_high);
    write_u64_parts(left ^ right, out_low, out_high);
}

#[no_mangle]
pub extern "C" fn uint64_not(
    low: u32,
    high: u32,
    out_low: *mut u32,
    out_high: *mut u32,
) {
    let value = make_u64(low, high);
    write_u64_parts(!value, out_low, out_high);
}

#[no_mangle]
pub extern "C" fn uint64_shl(
    low: u32,
    high: u32,
    bits: u32,
    out_low: *mut u32,
    out_high: *mut u32,
) {
    let value = make_u64(low, high);
    let shift = bits & 63;
    write_u64_parts(value.wrapping_shl(shift), out_low, out_high);
}

#[no_mangle]
pub extern "C" fn uint64_shr(
    low: u32,
    high: u32,
    bits: u32,
    out_low: *mut u32,
    out_high: *mut u32,
) {
    let value = make_u64(low, high);
    let shift = bits & 63;
    write_u64_parts(value.wrapping_shr(shift), out_low, out_high);
}

#[no_mangle]
pub extern "C" fn uint64_eq(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
) -> bool {
    make_u64(left_low, left_high) == make_u64(right_low, right_high)
}

#[no_mangle]
pub extern "C" fn uint64_lt(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
) -> bool {
    make_u64(left_low, left_high) < make_u64(right_low, right_high)
}

#[no_mangle]
pub extern "C" fn uint64_lte(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
) -> bool {
    make_u64(left_low, left_high) <= make_u64(right_low, right_high)
}

#[no_mangle]
pub extern "C" fn uint64_gt(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
) -> bool {
    make_u64(left_low, left_high) > make_u64(right_low, right_high)
}

#[no_mangle]
pub extern "C" fn uint64_gte(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
) -> bool {
    make_u64(left_low, left_high) >= make_u64(right_low, right_high)
}

#[no_mangle]
pub extern "C" fn uint64_is_zero(low: u32, high: u32) -> bool {
    make_u64(low, high) == 0
}

#[no_mangle]
pub extern "C" fn uint64_is_negative_signed(low: u32, high: u32) -> bool {
    (make_u64(low, high) & (1u64 << 63)) != 0
}

#[no_mangle]
pub extern "C" fn uint64_mul_full(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
    out_low_low: *mut u32,
    out_low_high: *mut u32,
    out_high_low: *mut u32,
    out_high_high: *mut u32,
) {
    let left = make_u64(left_low, left_high);
    let right = make_u64(right_low, right_high);
    let product = (left as u128) * (right as u128);
    let low = product as u64;
    let high = (product >> 64) as u64;
    write_u64_parts(low, out_low_low, out_low_high);
    write_u64_parts(high, out_high_low, out_high_high);
}

#[no_mangle]
pub extern "C" fn uint64_mul_full_signed(
    left_low: u32,
    left_high: u32,
    right_low: u32,
    right_high: u32,
    out_low_low: *mut u32,
    out_low_high: *mut u32,
    out_high_low: *mut u32,
    out_high_high: *mut u32,
) {
    let left = make_u64(left_low, left_high) as i64;
    let right = make_u64(right_low, right_high) as i64;
    let product = (left as i128) * (right as i128);
    let product_u = product as u128;
    let low = product_u as u64;
    let high = (product_u >> 64) as u64;
    write_u64_parts(low, out_low_low, out_low_high);
    write_u64_parts(high, out_high_low, out_high_high);
}

#[no_mangle]
pub extern "C" fn uint128_divmod_u64(
    low_low: u32,
    low_high: u32,
    high_low: u32,
    high_high: u32,
    div_low: u32,
    div_high: u32,
    out_q_low: *mut u32,
    out_q_high: *mut u32,
    out_r_low: *mut u32,
    out_r_high: *mut u32,
) -> bool {
    let low = make_u64(low_low, low_high);
    let high = make_u64(high_low, high_high);
    let divisor = make_u64(div_low, div_high);
    if divisor == 0 {
        return false;
    }
    let dividend = ((high as u128) << 64) | (low as u128);
    let quotient = dividend / (divisor as u128);
    if quotient > (u64::MAX as u128) {
        return false;
    }
    let remainder = dividend % (divisor as u128);
    write_u64_parts(quotient as u64, out_q_low, out_q_high);
    write_u64_parts(remainder as u64, out_r_low, out_r_high);
    true
}

#[no_mangle]
pub extern "C" fn int128_divmod_i64(
    low_low: u32,
    low_high: u32,
    high_low: u32,
    high_high: u32,
    divisor: i64,
    out_q: *mut i64,
    out_r: *mut i64,
) -> bool {
    if divisor == 0 {
        return false;
    }
    let low = make_u64(low_low, low_high);
    let high = make_u64(high_low, high_high);
    let dividend_u = ((high as u128) << 64) | (low as u128);
    let dividend = dividend_u as i128;
    let divisor_i = divisor as i128;
    let quotient = dividend / divisor_i;
    let remainder = dividend % divisor_i;
    if quotient < (i64::MIN as i128) || quotient > (i64::MAX as i128) {
        return false;
    }
    unsafe {
        if !out_q.is_null() {
            *out_q = quotient as i64;
        }
        if !out_r.is_null() {
            *out_r = remainder as i64;
        }
    }
    true
}
