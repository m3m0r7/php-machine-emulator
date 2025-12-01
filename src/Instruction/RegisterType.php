<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction;

/**
 * CPU Register Types.
 *
 * In 64-bit mode, EAX-EDI are the lower 32 bits of RAX-RDI.
 * The emulator stores 64-bit values internally for all GPRs.
 * Access size is determined by instruction operand size.
 *
 * Register encoding (3-bit base + REX.B extension for 64-bit):
 *   000 = EAX/RAX, 001 = ECX/RCX, 010 = EDX/RDX, 011 = EBX/RBX
 *   100 = ESP/RSP, 101 = EBP/RBP, 110 = ESI/RSI, 111 = EDI/RDI
 *   With REX.B=1: R8-R15
 */
enum RegisterType
{
    // Legacy 32-bit GPRs (lower 32 bits of 64-bit registers)
    case EAX;
    case ECX;
    case EDX;
    case EBX;
    case ESP;
    case EBP;
    case ESI;
    case EDI;

    // 64-bit extended registers (R8-R15, only accessible in 64-bit mode)
    case R8;
    case R9;
    case R10;
    case R11;
    case R12;
    case R13;
    case R14;
    case R15;

    // Special memory-mapped EDI for efficient DI operations
    case EDI_ON_MEMORY;

    // Segment registers
    case ES;
    case CS;
    case SS;
    case DS;
    case FS;
    case GS;

    // Instruction pointer (used for 64-bit RIP-relative addressing)
    case RIP;
}
