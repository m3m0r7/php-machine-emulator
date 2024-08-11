[bits 16]

main:
  ; Setup segments
  cli
  xor ax, ax
  xor bx, bx
  mov ds, ax
  mov es, ax
  mov ss, ax

  .loop_enter_message:
    call fill_zero_bytes

    mov si, enter_message
    call print_string

    mov di, buffer
    call input

    mov si, entered_message
    call print_string

    mov si, buffer
    call print_string

    mov si, new_line
    call print_string

    mov si, new_line
    call print_string

  hlt

input:
  mov ah, 0x00
  int 0x16

  mov [di], al
  mov ah, 0x0E
  int 0x10

  cmp al, 0x0D
  je .end

  cmp al, 0x0A
  je .end

  stosb

  jmp input

  .end:
    mov si, new_line
    call print_string
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

fill_zero_bytes:
  mov cx, 256
  mov di, buffer
  .loop:
    mov al, 0x00
    mov [di], al
    stosb
  loop .loop
  ret

buffer:
  times 256 db 0

enter_message:
  db 'Enter your string: ', 0

entered_message:
  db 'Your entered string is: ', 0

new_line:
  db 0x0D, 0x0A, 0

times 510-($-$$) db 0
dw 0xAA55

