; Test printf-like %d formatting in protected mode
; This tests that stack arguments are correctly read
;
; Flow:
; 1. Enter protected mode
; 2. Push arguments: 42, format string addr
; 3. Call mini_printf which reads stack args
; 4. Display result via video memory
;
; Expected: Video memory at 0xB8000 contains "Row:42 Pos:10,5"
; Markers: 0xB000 = 0xAA (start), 0xB001 = 0xFF (success)

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

    ; Clear screen
    mov edi, 0xB8000
    mov ecx, 2000       ; 80*25
    mov ax, 0x0720      ; space with white on black
    rep stosw

    ; Test 1: Simple printf with %d
    ; cdecl: push args right to left, then call
    push dword 42           ; arg1: the number to format
    push dword fmt_string   ; arg0: format string pointer
    call mini_printf
    add esp, 8              ; clean up stack (cdecl)

    ; Test 2: Multiple %d
    push dword 5            ; arg2: col
    push dword 10           ; arg1: row
    push dword fmt_pos      ; arg0: format string
    call mini_printf
    add esp, 12

    ; Write success marker
    mov byte [0xB001], 0xFF

.halt:
    cli
    hlt
    jmp .halt

;------------------------------------------------------------
; mini_printf: Minimal printf implementation
; Uses cdecl calling convention
; Stack: [esp+4] = format string ptr, [esp+8] = first arg, etc.
;------------------------------------------------------------
mini_printf:
    push ebp
    mov ebp, esp
    push esi
    push edi
    push ebx

    mov esi, [ebp+8]        ; format string pointer
    lea ebx, [ebp+12]       ; pointer to first argument

    ; Video memory position (static, increments)
    mov edi, [video_pos]

.loop:
    lodsb                   ; al = next char from format string
    test al, al
    jz .done

    cmp al, '%'
    je .format_spec

    ; Regular character - write to video memory
    mov ah, 0x07            ; white on black
    mov [edi], ax
    add edi, 2
    jmp .loop

.format_spec:
    lodsb                   ; get char after %
    test al, al
    jz .done

    cmp al, 'd'
    je .print_decimal
    cmp al, '%'
    je .print_percent
    ; Unknown format - print literal
    mov ah, 0x07
    push ax
    mov al, '%'
    mov [edi], ax
    add edi, 2
    pop ax
    mov [edi], ax
    add edi, 2
    jmp .loop

.print_percent:
    mov ah, 0x07
    mov al, '%'
    mov [edi], ax
    add edi, 2
    jmp .loop

.print_decimal:
    ; Get argument from stack
    mov eax, [ebx]
    add ebx, 4              ; advance to next argument

    ; Convert number to decimal and print
    call print_decimal_eax
    jmp .loop

.done:
    ; Save video position for next call
    mov [video_pos], edi

    pop ebx
    pop edi
    pop esi
    pop ebp
    ret

;------------------------------------------------------------
; print_decimal_eax: Print EAX as decimal to video memory at EDI
; Modifies: EAX, ECX, EDX
;------------------------------------------------------------
print_decimal_eax:
    push ebx
    push esi

    ; Handle 0 specially
    test eax, eax
    jnz .not_zero
    mov ah, 0x07
    mov al, '0'
    mov [edi], ax
    add edi, 2
    jmp .print_done

.not_zero:
    ; Convert to decimal digits (reverse order)
    mov ecx, 0              ; digit count
    mov ebx, 10

.convert_loop:
    xor edx, edx
    div ebx                 ; eax = quotient, edx = remainder
    push edx                ; save digit
    inc ecx
    test eax, eax
    jnz .convert_loop

    ; Print digits (they're on stack in correct order now)
.print_loop:
    pop eax
    add al, '0'
    mov ah, 0x07
    mov [edi], ax
    add edi, 2
    loop .print_loop

.print_done:
    pop esi
    pop ebx
    ret

;------------------------------------------------------------
; Data
;------------------------------------------------------------
video_pos:
    dd 0xB8000              ; current video memory position

fmt_string:
    db "Row:%d", 0

fmt_pos:
    db " Pos:%d,%d", 0

;------------------------------------------------------------
; GDT
;------------------------------------------------------------
align 8
gdt_start:
    dq 0                    ; null descriptor

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
