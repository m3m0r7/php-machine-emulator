[bits 16]
[org 0x1500]

main:
  cli
  xor ax, ax
  xor bx, bx
  mov ds, ax
  mov es, ax
  mov ss, ax
  mov sp, 0x1500
  sti

  mov si, hello_world
  call print_string
  hlt

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

hello_world:
  db "Hello World!", 0x0D, 0x0A, 0

times 512-($-$$) db 0

