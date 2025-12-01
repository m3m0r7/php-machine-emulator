<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Window;

enum SDLScancode: int
{
    // Letters A-Z
    case A = 4;
    case B = 5;
    case C = 6;
    case D = 7;
    case E = 8;
    case F = 9;
    case G = 10;
    case H = 11;
    case I = 12;
    case J = 13;
    case K = 14;
    case L = 15;
    case M = 16;
    case N = 17;
    case O = 18;
    case P = 19;
    case Q = 20;
    case R = 21;
    case S = 22;
    case T = 23;
    case U = 24;
    case V = 25;
    case W = 26;
    case X = 27;
    case Y = 28;
    case Z = 29;

    // Numbers 0-9
    case NUM_1 = 30;
    case NUM_2 = 31;
    case NUM_3 = 32;
    case NUM_4 = 33;
    case NUM_5 = 34;
    case NUM_6 = 35;
    case NUM_7 = 36;
    case NUM_8 = 37;
    case NUM_9 = 38;
    case NUM_0 = 39;

    // Special keys
    case RETURN = 40;
    case ESCAPE = 41;
    case BACKSPACE = 42;
    case TAB = 43;
    case SPACE = 44;

    // Function keys
    case F1 = 58;
    case F2 = 59;
    case F3 = 60;
    case F4 = 61;
    case F5 = 62;
    case F6 = 63;
    case F7 = 64;
    case F8 = 65;
    case F9 = 66;
    case F10 = 67;
    case F11 = 68;
    case F12 = 69;

    // Arrow keys
    case RIGHT = 79;
    case LEFT = 80;
    case DOWN = 81;
    case UP = 82;

    // Modifier keys
    case LCTRL = 224;
    case LSHIFT = 225;
    case LALT = 226;
    case RCTRL = 228;
    case RSHIFT = 229;
    case RALT = 230;

    public function isModifier(): bool
    {
        return match ($this) {
            self::LSHIFT, self::RSHIFT,
            self::LCTRL, self::RCTRL,
            self::LALT, self::RALT => true,
            default => false,
        };
    }
}
