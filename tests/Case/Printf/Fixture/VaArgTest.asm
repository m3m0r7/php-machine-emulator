; Test va_arg-style argument access in protected mode
; Simulates how COM32's printf accesses variadic arguments
;
; This test creates a stack frame like:
;   [ebp+8]  = format string pointer
;   [ebp+12] = first variadic argument (row number)
;
; Then uses ebp-relative addressing to access arguments,
; similar to how va_arg works in compiled C code.
;
; Expected: Video memory contains "Test:7"
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

    ; Call va_arg_printf with arguments on stack (cdecl)
    ; Simulate: va_arg_printf("Test:%d", 7)
    push dword 7            ; arg1: the number
    push dword fmt_string   ; arg0: format string
    call va_arg_printf
    add esp, 8

    ; Write success marker
    mov byte [0xB001], 0xFF

.halt:
    cli
    hlt
    jmp .halt

;------------------------------------------------------------
; va_arg_printf: Printf implementation using va_arg-style access
; Uses EBP-relative addressing like compiled C code
;------------------------------------------------------------
va_arg_printf:
    push ebp
    mov ebp, esp
    push esi
    push edi
    push ebx
    push ecx

    ; Setup like compiled C code
    mov esi, [ebp+8]        ; format = first argument
    lea ebx, [ebp+12]       ; ap = pointer to second argument (va_list)

    mov edi, [video_pos]

.loop:
    ; Read character from format string
    movzx eax, byte [esi]   ; al = *format
    inc esi                 ; format++

    test al, al
    jz .done

    cmp al, '%'
    jne .regular_char

    ; Format specifier - get next char
    movzx eax, byte [esi]
    inc esi

    test al, al
    jz .done

    cmp al, 'd'
    je .handle_d
    cmp al, '%'
    je .handle_percent

    ; Unknown - output %X
    mov byte [edi], '%'
    mov byte [edi+1], 0x07
    add edi, 2
    mov [edi], al
    mov byte [edi+1], 0x07
    add edi, 2
    jmp .loop

.handle_percent:
    mov byte [edi], '%'
    mov byte [edi+1], 0x07
    add edi, 2
    jmp .loop

.handle_d:
    ; va_arg(ap, int): value = *(int*)ap; ap += 4;
    mov eax, [ebx]          ; value = *ap (dereference va_list pointer)
    add ebx, 4              ; ap += sizeof(int)

    ; Convert integer to decimal string
    call print_int_eax
    jmp .loop

.regular_char:
    mov [edi], al
    mov byte [edi+1], 0x07
    add edi, 2
    jmp .loop

.done:
    mov [video_pos], edi

    pop ecx
    pop ebx
    pop edi
    pop esi
    pop ebp
    ret

;------------------------------------------------------------
; print_int_eax: Print EAX as decimal
;------------------------------------------------------------
print_int_eax:
    push ebx
    push ecx
    push edx

    test eax, eax
    jnz .not_zero

    mov byte [edi], '0'
    mov byte [edi+1], 0x07
    add edi, 2
    jmp .done

.not_zero:
    xor ecx, ecx            ; digit count
    mov ebx, 10

.convert:
    xor edx, edx
    div ebx                 ; eax/10, remainder in edx
    push edx
    inc ecx
    test eax, eax
    jnz .convert

.output:
    pop eax
    add al, '0'
    mov [edi], al
    mov byte [edi+1], 0x07
    add edi, 2
    loop .output

.done:
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
    db "Test:%d", 0

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
