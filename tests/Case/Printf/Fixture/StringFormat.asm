; Test string formatting in protected mode
; Simulates simplified printf %d handling
;
; This test:
; 1. Iterates through a format string
; 2. When seeing '%', checks next char
; 3. If 'd', outputs the decimal value from argument
; 4. Otherwise, outputs the literal char
;
; Expected: Video memory contains "Row:5"
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
    mov ecx, 2000
    mov ax, 0x0720
    rep stosw

    ; Reset video position
    mov dword [video_pos], 0xB8000

    ; Test: format "Row:%d" with arg=5
    ; Push argument first (cdecl right-to-left)
    push dword 5            ; arg1: the number
    push dword fmt_string   ; arg0: format string
    call simple_printf
    add esp, 8

    ; Write success marker
    mov byte [0xB001], 0xFF

.halt:
    cli
    hlt
    jmp .halt

;------------------------------------------------------------
; simple_printf: Minimal printf-like function
; Stack: [esp+4] = format string ptr, [esp+8] = first arg
; Uses C-style string iteration with pointer increment
;------------------------------------------------------------
simple_printf:
    push ebp
    mov ebp, esp
    push esi
    push edi
    push ebx

    mov esi, [ebp+8]        ; format string pointer
    lea ebx, [ebp+12]       ; pointer to first argument

    mov edi, [video_pos]

.loop:
    ; Load byte from format string using byte ptr
    mov al, [esi]           ; Read current char
    inc esi                 ; Advance pointer (like p++ in C)

    test al, al             ; Check for null terminator
    jz .done

    cmp al, '%'
    je .format_spec

    ; Regular character - write to video memory
    mov ah, 0x07            ; white on black
    mov [edi], ax
    add edi, 2
    jmp .loop

.format_spec:
    ; Get next char after %
    mov al, [esi]
    inc esi

    test al, al
    jz .done

    cmp al, 'd'
    je .print_decimal
    cmp al, '%'
    je .print_percent

    ; Unknown format - print literal %
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
    ; Get argument from stack (dword pointer in ebx)
    mov eax, [ebx]
    add ebx, 4              ; advance to next argument

    ; Convert number to decimal and print
    call print_decimal_eax
    jmp .loop

.done:
    ; Save video position
    mov [video_pos], edi

    pop ebx
    pop edi
    pop esi
    pop ebp
    ret

;------------------------------------------------------------
; print_decimal_eax: Print EAX as decimal to video memory at EDI
;------------------------------------------------------------
print_decimal_eax:
    push ebx
    push ecx
    push edx

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
    pop edx
    pop ecx
    pop ebx
    ret

;------------------------------------------------------------
; Data
;------------------------------------------------------------
video_pos:
    dd 0xB8000

fmt_string:
    db "Row:%d", 0

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
