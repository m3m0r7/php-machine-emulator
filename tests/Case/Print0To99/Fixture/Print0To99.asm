[bits 16]

%define length 3
%define loop_for_size 100

mov cx, 100
mov dx, 0
loop_for:

  push cx
  push dx
  mov ax, dx
  mov si, buffer
  call itoa
  pop dx
  pop cx

  mov si, buffer
  call print_string
  mov si, newline
  call print_string

  inc dx
loop loop_for

hlt


print_string:
  lodsb

  cmp al, 0
  jz .done

  call .char
  jmp .done

  .char:
    mov ah, 0x0E

    int 0x10
    jmp print_string
  .done:
    ret

itoa:
  push si
  push cx
  mov cx, length+1
  .fill_by_zero:
    mov bl, 0x00
    mov [si], bl
    inc si
  loop .fill_by_zero
  pop cx

  pop si
  push si
  mov bx, 10
  .loop:
    xor dx, dx
    div bx
    add dl, '0'
    mov [si+length-1], dl
    dec si
    cmp al, 0
    jnz .loop

  pop si
  mov di, si

  .seek_to_nonnull_ahead:
    lodsb
    cmp al, 0
    jz .seek_to_nonnull_ahead
  dec si

  .move_to_ahead:
    lodsb
    cmp al, 0
    jz .finish
    mov [di], al
    inc di
    jmp .move_to_ahead
    .finish:
  mov al, 0x00
  mov [di], al

  ret

buffer:
  times length+1 db 0

newline:
  db 0x0D, 0x0A, 0
