<?php

declare(strict_types=1);

namespace PHPMachineEmulator;

enum ArchitectureType: string
{
    /**
     * Intel x86 (32-bit) architecture.
     * Supports Real Mode, Protected Mode.
     */
    case Intel_x86 = 'Intel_x86';

    /**
     * Intel x86-64 (64-bit) architecture.
     * Supports Real Mode, Protected Mode, Long Mode (64-bit).
     * Also known as AMD64, x64, or EM64T.
     */
    case Intel_x86_64 = 'Intel_x86_64';
}
