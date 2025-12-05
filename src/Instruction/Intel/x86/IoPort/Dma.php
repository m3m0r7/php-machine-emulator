<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\IoPort;

/**
 * DMA (Direct Memory Access) Controller 8237A I/O ports.
 *
 * The PC uses two cascaded 8237A DMA controllers:
 * - DMA1: Channels 0-3 (8-bit transfers)
 * - DMA2: Channels 4-7 (16-bit transfers), Channel 4 used for cascade
 */
enum Dma: int
{
    // DMA1 (8-bit) controller ports
    case DMA1_STATUS_COMMAND = 0x08;
    case DMA1_REQUEST = 0x09;
    case DMA1_SINGLE_MASK = 0x0A;
    case DMA1_MODE = 0x0B;
    case DMA1_CLEAR_FF = 0x0C;
    case DMA1_MASTER_CLEAR = 0x0D;
    case DMA1_CLEAR_MASK = 0x0E;
    case DMA1_MULTI_MASK = 0x0F;

    // DMA2 (16-bit) controller ports
    case DMA2_STATUS_COMMAND = 0xD0;
    case DMA2_REQUEST = 0xD2;
    case DMA2_SINGLE_MASK = 0xD4;
    case DMA2_MODE = 0xD6;
    case DMA2_CLEAR_FF = 0xD8;
    case DMA2_MASTER_CLEAR = 0xDA;
    case DMA2_CLEAR_MASK = 0xDC;
    case DMA2_MULTI_MASK = 0xDE;

    // Page registers (for 24-bit addressing)
    case PAGE_CH0 = 0x87;
    case PAGE_CH1 = 0x83;
    case PAGE_CH2 = 0x81;
    case PAGE_CH3 = 0x82;
    case PAGE_CH5 = 0x8B;
    case PAGE_CH6 = 0x89;
    case PAGE_CH7 = 0x8A;

    public static function isDma1Port(int $port): bool
    {
        return $port >= self::DMA1_STATUS_COMMAND->value && $port <= self::DMA1_MULTI_MASK->value;
    }

    public static function isDma2Port(int $port): bool
    {
        return $port >= self::DMA2_STATUS_COMMAND->value && $port <= self::DMA2_MULTI_MASK->value;
    }
}
