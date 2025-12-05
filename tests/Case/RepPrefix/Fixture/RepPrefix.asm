; test_rep_prefix.asm - Test REP prefix with override prefixes
[BITS 16]
[ORG 0x7C00]

%define MARKER  0xB000
%define SRC1    0x1000
%define SRC2    0x2000
%define DST     0x8000

start:
    xor ax, ax
    mov ds, ax
    mov es, ax
    mov ss, ax
    mov sp, 0x7C00
    mov byte [MARKER], 0xAA

    ; Test 1: Basic REP MOVSB
    mov dword [SRC1], 0x44332211
    mov dword [DST], 0
    mov si, SRC1
    mov di, DST
    mov cx, 4
    cld
    rep movsb
    cmp dword [DST], 0x44332211
    jne .e1
    mov byte [MARKER+1], 0x11
    jmp .t2
.e1:
    mov byte [MARKER+1], 0xE1
    jmp .halt

    ; Test 2: REP SS: MOVSB (F3 36 A4) - segment override test
    ; SS: override changes source from DS:SI to SS:SI
    ; Setup: SS=0x100, SI=0x1000 -> source = 0x100*16 + 0x1000 = 0x2000
    ;        ES=0, DI=0x8010 -> dest = 0x8010
    ; Put different data at DS:0x1000 and SS:0x1000 (=0x2000) to verify override works
.t2:
    mov dword [SRC1], 0x11111111      ; DS:0x1000 = 0x11111111 (wrong source)
    mov dword [SRC2], 0xDDCCBBAA      ; 0x2000 = 0xDDCCBBAA (correct source via SS:0x1000)
    mov dword [DST+0x10], 0           ; Clear destination
    mov ax, 0x100
    mov ss, ax                        ; SS = 0x100
    xor ax, ax
    mov es, ax                        ; ES = 0
    mov si, SRC1                      ; SI = 0x1000 (SS:SI = 0x100:0x1000 = 0x2000)
    mov di, DST+0x10                  ; DI = 0x8010 (ES:DI = 0:0x8010 = 0x8010)
    mov cx, 4
    cld
    db 0xF3, 0x36, 0xA4               ; REP SS: MOVSB
    xor ax, ax
    mov ss, ax                        ; Restore SS = 0
    cmp dword [DST+0x10], 0xDDCCBBAA  ; Should have SS:SI data, not DS:SI
    jne .e2
    mov byte [MARKER+2], 0x22
    jmp .t3
.e2:
    mov byte [MARKER+2], 0xE2
    jmp .halt

    ; Test 3: REP STOSD (F3 66 AB)
.t3:
    mov dword [DST+0x30], 0xDEADBEEF
    mov dword [DST+0x34], 0xCAFEBABE
    xor eax, eax
    mov edi, DST+0x30
    mov ecx, 2
    cld
    db 0xF3, 0x66, 0xAB  ; REP STOSD
    cmp dword [DST+0x30], 0
    jne .e3
    cmp dword [DST+0x34], 0
    jne .e3
    mov byte [MARKER+3], 0x33
    jmp .t4
.e3:
    mov byte [MARKER+3], 0xE3
    jmp .halt

    ; Test 4: REP 66 67 STOSD
.t4:
    mov dword [DST+0x60], 0xFFFFFFFF
    mov dword [DST+0x64], 0xFFFFFFFF
    mov eax, 0x12345678
    mov edi, DST+0x60
    mov ecx, 2
    cld
    db 0xF3, 0x66, 0x67, 0xAB
    cmp dword [DST+0x60], 0x12345678
    jne .e4
    cmp dword [DST+0x64], 0x12345678
    jne .e4
    mov byte [MARKER+4], 0x44
    jmp .t5
.e4:
    mov byte [MARKER+4], 0xE4
    jmp .halt

    ; Test 5: REPNE SCASB (F2 AE)
.t5:
    mov dword [DST+0x80], 'HELL'
    mov word [DST+0x84], 'O'
    mov byte [DST+0x85], 0
    mov di, DST+0x80
    mov al, 0
    mov cx, 10
    cld
    db 0xF2, 0xAE
    cmp cx, 4
    jne .e5
    mov byte [MARKER+5], 0x55
    jmp .ok
.e5:
    mov byte [MARKER+5], 0xE5
    jmp .halt

.ok:
    mov byte [MARKER+15], 0xFF
.halt:
    hlt
    jmp .halt

times 510-($-$$) db 0
dw 0xAA55
