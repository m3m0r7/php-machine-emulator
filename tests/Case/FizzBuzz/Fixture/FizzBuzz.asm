[bits 16]

%define length 3
%define fizzbuzz_loops 100

mov cx, 0

loop_for_fizzbuzz:
  inc cx

  mov ax, cx

  mov bx, 15
  mov si, fizzbuzz
  call fizzbuzz_output
  jz .finish


  mov bx, 5
  mov si, buzz
  call fizzbuzz_output
  jz .finish

  mov bx, 3
  mov si, fizz
  call fizzbuzz_output
  jz .finish

  mov si, buffer
  call itoa

  mov si, buffer
  call print_string


  .finish:
    ; 改行の出力
    mov si, newline
    call print_string

  cmp cx, fizzbuzz_loops
jnz loop_for_fizzbuzz

hlt

fizzbuzz_output:
  push ax
  xor dx, dx
  div bx

  cmp dl, 0
  jnz .fizzbuzz_finished

  call print_string

  .fizzbuzz_finished:

  pop ax
  ret


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

fizzbuzz:
  db 'FizzBuzz', 0

buzz:
  db 'Buzz', 0

fizz:
  db 'Fizz', 0

newline:
  db 0x0D, 0x0A, 0

times 510-($-$$) db 0

dw 0xAA55
