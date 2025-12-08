; Test printf with escape sequence format string
; Simulates: printf("\033[%d;1H", 5)
;
; The format string contains an ESC character (0x1B) followed by [%d;1H
; This is exactly what SYSLINUX menu uses for cursor positioning.
;
; Expected output: ESC[5;1H (0x1B 0x5B 0x35 0x3B 0x31 0x48)
; Then: ";1H" should NOT appear literally on screen
;
; Markers: 0xB000 = 0xAA (start), 0xB001 = 0xFF (success)
; Output buffer at 0xB100-0xB1FF stores formatted output

[BITS 16]
[ORG 0x7C00]

start:
    cli
    xor ax, ax
    mov ds, ax
    mov es, ax
    mov ss, ax
    mov sp, 0x7C00

    ; Write start marker
    mov byte [0xB000], 0xAA

    ; Enable A20
    in al, 0x92
    or al, 2
    out 0x92, al

    ; Load GDT
    lgdt [gdt_descriptor]

    ; Enter protected mode
    mov eax, cr0
    or eax, 1
    mov cr0, eax

    jmp 0x08:protected_mode

[BITS 32]
protected_mode:
    mov ax, 0x10
    mov ds, ax
    mov es, ax
    mov fs, ax
    mov gs, ax
    mov ss, ax
    mov esp, 0x7C00

    ; Initialize output buffer
    mov edi, 0xB100
    mov dword [output_ptr], edi

    ; Call format function: format("\033[%d;1H", 5)
    push dword 5            ; arg: row number
    push dword fmt_escape   ; format string with ESC
    call format_string
    add esp, 8

    ; Verify output buffer content
    ; Should be: ESC [ 5 ; 1 H (6 bytes)
    mov esi, 0xB100

    ; Check byte 0: ESC (0x1B)
    cmp byte [esi], 0x1B
    jne .fail

    ; Check byte 1: [ (0x5B)
    cmp byte [esi+1], 0x5B
    jne .fail

    ; Check byte 2: 5 (0x35) - the formatted number!
    cmp byte [esi+2], '5'
    jne .fail

    ; Check byte 3: ; (0x3B)
    cmp byte [esi+3], ';'
    jne .fail

    ; Check byte 4: 1 (0x31)
    cmp byte [esi+4], '1'
    jne .fail

    ; Check byte 5: H (0x48)
    cmp byte [esi+5], 'H'
    jne .fail

    ; Also verify it's NOT "d;1H" (format failed)
    cmp byte [esi+2], 'd'
    je .fail

    ; Success!
    mov byte [0xB001], 0xFF
    jmp .halt

.fail:
    mov byte [0xB001], 0x00  ; Failure marker

.halt:
    cli
    hlt
    jmp .halt

;------------------------------------------------------------
; format_string: Format string with %d conversion
; Stack: [esp+4] = format, [esp+8] = arg
; Output goes to buffer at [output_ptr]
;------------------------------------------------------------
format_string:
    push ebp
    mov ebp, esp
    push esi
    push edi
    push ebx

    mov esi, [ebp+8]        ; format string
    lea ebx, [ebp+12]       ; va_list (pointer to first arg)
    mov edi, [output_ptr]   ; output buffer

.loop:
    movzx eax, byte [esi]
    inc esi

    test al, al
    jz .done

    cmp al, '%'
    je .format

    ; Regular character - copy to output
    mov [edi], al
    inc edi
    jmp .loop

.format:
    ; Get char after %
    movzx eax, byte [esi]
    inc esi

    test al, al
    jz .done

    cmp al, 'd'
    je .do_decimal
    cmp al, '%'
    je .do_percent

    ; Unknown format - output %X
    mov byte [edi], '%'
    inc edi
    mov [edi], al
    inc edi
    jmp .loop

.do_percent:
    mov byte [edi], '%'
    inc edi
    jmp .loop

.do_decimal:
    ; Get argument: value = *va_list; va_list += 4
    mov eax, [ebx]
    add ebx, 4

    ; Convert to decimal
    call output_decimal
    jmp .loop

.done:
    mov byte [edi], 0       ; null terminate
    mov [output_ptr], edi

    pop ebx
    pop edi
    pop esi
    pop ebp
    ret

;------------------------------------------------------------
; output_decimal: Output EAX as decimal to buffer at EDI
;------------------------------------------------------------
output_decimal:
    push ebx
    push ecx
    push edx

    test eax, eax
    jnz .not_zero
    mov byte [edi], '0'
    inc edi
    jmp .done

.not_zero:
    xor ecx, ecx
    mov ebx, 10

.convert:
    xor edx, edx
    div ebx
    push edx
    inc ecx
    test eax, eax
    jnz .convert

.output:
    pop eax
    add al, '0'
    mov [edi], al
    inc edi
    loop .output

.done:
    pop edx
    pop ecx
    pop ebx
    ret

;------------------------------------------------------------
; Data
;------------------------------------------------------------
output_ptr:
    dd 0xB100

fmt_escape:
    db 0x1B, "[%d;1H", 0    ; ESC [ %d ; 1 H

;------------------------------------------------------------
; GDT
;------------------------------------------------------------
align 8
gdt_start:
    dq 0

    ; Code segment (0x08)
    dw 0xFFFF, 0x0000
    db 0x00, 10011010b, 11001111b, 0x00

    ; Data segment (0x10)
    dw 0xFFFF, 0x0000
    db 0x00, 10010010b, 11001111b, 0x00

gdt_end:

gdt_descriptor:
    dw gdt_end - gdt_start - 1
    dd gdt_start

times 510-($-$$) db 0
dw 0xAA55
