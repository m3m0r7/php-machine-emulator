<?php
declare(strict_types=1);
namespace PHPMachineEmulator\Instruction\Intel\ServiceFunction;

enum VideoServiceFunction: int
{
    case SET_VIDEO_MODE = 0x00;
    case SET_CURSOR_SHAPE = 0x01;
    case SET_CURSOR_POSITION = 0x02;
    case GET_CURSOR_POSITION = 0x03;
    case SELECT_ACTIVE_DISPLAY_PAGE = 0x04;
    case SET_ACTIVE_DISPLAY_PAGE = 0x05;
    case SCROLL_UP_WINDOW = 0x06;
    case SCROLL_DOWN_WINDOW = 0x07;
    case READ_CHARACTER_AND_ATTRIBUTE_AT_CURSOR_POSITION = 0x08;
    case WRITE_CHARACTER_AND_ATTRIBUTE_AT_CURSOR_POSITION = 0x09;
    case WRITE_CHARACTER_ONLY_AT_CURSOR_POSITION = 0x0A;
    case SET_COLOR_PALETTE = 0x0B;
    case READ_PIXEL = 0x0C;
    case WRITE_PIXEL = 0x0D;
    case TELETYPE_OUTPUT = 0x0E;
    case GET_CURRENT_VIDEO_MODE = 0x0F;
}
