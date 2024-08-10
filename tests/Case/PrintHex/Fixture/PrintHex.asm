[bits 16]

main:
  ; Setup segments
  cli
  xor ax, ax
  xor bx, bx
  mov ds, ax
  mov es, ax
  mov ss, ax
  mov sp, 0x7C00

  mov si, output

  ; NOTE: Specified 6 bytes in output variable
  mov cx, 6

  output_loop:
    push si

    ; NOTE: Output "0x"
    mov si, zero_x
    call print_string


    ; NOTE: Output Hex value
    pop si
    call print_hex
    push si


    ; NOTE: Output " "
    mov si, space
    call print_string

    pop si
    loop output_loop
  hlt

print_hex:
  mov al, [si]
  shr al, 4
  call .to_hex

  mov al, [si]
  and al, 0x0F
  call .to_hex

  lodsb
  ret

  .to_hex:
    ; NOTE: Adding '0' (0x30) will result to get a 0-9 as a character
    add al, '0'
    cmp al, '9'
    jbe .output_char

    ; NOTE: Add 7 to set the offset to 'A' (0x41).
    ;       Currently, the offset is ':' (0x3A). Adding 7 will result in 0x41.
    add al, 7

    .output_char:
      mov ah, 0x0E
      int 0x10
      ret
  ret

print_string:
  lodsb
  or al, al
  jz .done
  call .char
  jmp .done
  .char:
    mov ah, 0x0E
    int 0x10
    jmp print_string
  .done:
    ret

zero_x:
  db "0x", 0

space:
  db " ", 0

output:
  ; NOTE: 0x2525COO1BEEF
  db 0x25, 0x25, 0xC0, 0x01, 0xBE, 0xEF
