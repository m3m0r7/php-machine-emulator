<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

class Sti extends Nop
{
    public function opcodes(): array
    {
        return [0xFB];
    }
}
