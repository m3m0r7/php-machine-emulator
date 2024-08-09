[bits 16]
[org 0x7C00]

main:
  cli
  xor ax, ax
  xor bx, bx
  mov ds, ax
  mov es, ax
  mov ss, ax
  mov sp, 0x7C00

  mov bx, 0x1000
  mov ah, 0x02
  mov al, 0x01
  mov ch, 0x00
  mov cl, 0x02
  mov dh, 0x00
  mov dl, 0x80

  int 0x13
  jc error

  jmp 0x1000

error:
  mov si, error_message
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

error_message:
  db "Read Disk Error!", 0x0D, 0x0A, 0

times 510-($-$$) db 0
dw 0xAA55
