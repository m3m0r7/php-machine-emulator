<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

/**
 * Local APIC (LAPIC) register offsets.
 *
 * @see Intel SDM Volume 3A, Chapter 10 - APIC
 */
enum LapicRegister: int
{
    case ID = 0x20;
    case Version = 0x30;
    case TaskPriority = 0x80;
    case ArbitrationPriority = 0x90;
    case ProcessorPriority = 0xA0;
    case EndOfInterrupt = 0xB0;
    case RemoteRead = 0xC0;
    case LogicalDestination = 0xD0;
    case DestinationFormat = 0xE0;
    case SpuriousInterruptVector = 0xF0;
    case InServiceStart = 0x100;
    case TriggerModeStart = 0x180;
    case InterruptRequestStart = 0x200;
    case ErrorStatus = 0x280;
    case LvtCmci = 0x2F0;
    case InterruptCommandLow = 0x300;
    case InterruptCommandHigh = 0x310;
    case LvtTimer = 0x320;
    case LvtThermal = 0x330;
    case LvtPerfMonCounter = 0x340;
    case LvtLint0 = 0x350;
    case LvtLint1 = 0x360;
    case LvtError = 0x370;
    case TimerInitialCount = 0x380;
    case TimerCurrentCount = 0x390;
    case TimerDivideConfiguration = 0x3E0;

    /**
     * Check if this register is read-only.
     */
    public function isReadOnly(): bool
    {
        return match ($this) {
            self::ID,
            self::Version,
            self::ArbitrationPriority,
            self::ProcessorPriority,
            self::RemoteRead,
            self::InServiceStart,
            self::TriggerModeStart,
            self::InterruptRequestStart,
            self::TimerCurrentCount => true,
            default => false,
        };
    }

    /**
     * Check if this register is write-only.
     */
    public function isWriteOnly(): bool
    {
        return $this === self::EndOfInterrupt;
    }

    /**
     * Get human-readable name for this register.
     */
    public function name(): string
    {
        return match ($this) {
            self::ID => 'LAPIC ID',
            self::Version => 'LAPIC Version',
            self::TaskPriority => 'Task Priority Register (TPR)',
            self::ArbitrationPriority => 'Arbitration Priority Register (APR)',
            self::ProcessorPriority => 'Processor Priority Register (PPR)',
            self::EndOfInterrupt => 'End of Interrupt (EOI)',
            self::RemoteRead => 'Remote Read Register (RRD)',
            self::LogicalDestination => 'Logical Destination Register (LDR)',
            self::DestinationFormat => 'Destination Format Register (DFR)',
            self::SpuriousInterruptVector => 'Spurious Interrupt Vector Register (SVR)',
            self::InServiceStart => 'In-Service Register (ISR)',
            self::TriggerModeStart => 'Trigger Mode Register (TMR)',
            self::InterruptRequestStart => 'Interrupt Request Register (IRR)',
            self::ErrorStatus => 'Error Status Register (ESR)',
            self::LvtCmci => 'LVT CMCI Register',
            self::InterruptCommandLow => 'Interrupt Command Register Low (ICR Low)',
            self::InterruptCommandHigh => 'Interrupt Command Register High (ICR High)',
            self::LvtTimer => 'LVT Timer Register',
            self::LvtThermal => 'LVT Thermal Sensor Register',
            self::LvtPerfMonCounter => 'LVT Performance Monitoring Counter',
            self::LvtLint0 => 'LVT LINT0 Register',
            self::LvtLint1 => 'LVT LINT1 Register',
            self::LvtError => 'LVT Error Register',
            self::TimerInitialCount => 'Timer Initial Count Register',
            self::TimerCurrentCount => 'Timer Current Count Register',
            self::TimerDivideConfiguration => 'Timer Divide Configuration Register',
        };
    }
}
