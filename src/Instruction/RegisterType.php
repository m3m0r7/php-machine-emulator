<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction;

enum RegisterType
{
    case EAX;
    case ECX;
    case EDX;
    case EBX;
    case ESP;
    case EBP;
    case ESI;
    case EDI;

    case ES;
    case CS;
    case SS;
    case DS;
    case FS;
    case GS;
}
