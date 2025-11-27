<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

class PicState
{
    public int $imrMaster = 0xFF;
    public int $imrSlave = 0xFF;
    public int $irrMaster = 0x00;
    public int $irrSlave = 0x00;
    public int $isrMaster = 0x00;
    public int $isrSlave = 0x00;
    private int $baseMaster = 0x08;
    private int $baseSlave = 0x70;
    private bool $expectIcw4Master = false;
    private bool $expectIcw4Slave = false;
    private int $icwStepMaster = 0;
    private int $icwStepSlave = 0;
    private bool $readIsrMaster = false;
    private bool $readIsrSlave = false;
    private array $pendingQueue = [];
    private array $irqPending = [];
    private bool $specialMaskMode = false;

    public function __construct(private ApicState $apicState)
    {
    }

    public function maskMaster(int $value): void
    {
        $this->imrMaster = $value & 0xFF;
    }

    public function maskSlave(int $value): void
    {
        $this->imrSlave = $value & 0xFF;
    }

    public function eoiMaster(?int $irq = null): void
    {
        if ($irq === null) {
            $this->isrMaster = 0;
            return;
        }
        $bit = 1 << ($irq & 0x7);
        $this->isrMaster &= ~$bit;
    }

    public function eoiSlave(?int $irq = null): void
    {
        if ($irq === null) {
            $this->isrSlave = 0;
            return;
        }
        $bit = 1 << ($irq & 0x7);
        $this->isrSlave &= ~$bit;
    }

    public function writeCommandMaster(int $value): void
    {
        $value &= 0xFF;
        if (($value & 0x10) !== 0) {
            // ICW1: start initialization
            $this->icwStepMaster = 1;
            $this->expectIcw4Master = ($value & 0x01) !== 0;
            $this->isrMaster = $this->irrMaster = 0;
            return;
        }

        // OCW3: select IRR/ISR read
        if (($value & 0x18) === 0x08) {
            $this->readIsrMaster = ($value & 0x08) !== 0;
            return;
        }

        // OCW2: EOI
        if (($value & 0xE0) === 0x20) {
            if (($value & 0xE0) === 0x60) {
                $irq = $value & 0x7;
                $this->eoiMaster($irq);
            } else {
                $this->eoiMaster(null);
            }
            return;
        }

        // Special Mask Mode (OCW3 bits)
        if (($value & 0x68) === 0x60) {
            $this->specialMaskMode = ($value & 0x40) !== 0;
        }
    }

    public function writeCommandSlave(int $value): void
    {
        $value &= 0xFF;
        if (($value & 0x10) !== 0) {
            $this->icwStepSlave = 1;
            $this->expectIcw4Slave = ($value & 0x01) !== 0;
            $this->isrSlave = $this->irrSlave = 0;
            return;
        }

        if (($value & 0x18) === 0x08) {
            $this->readIsrSlave = ($value & 0x08) !== 0;
            return;
        }

        if (($value & 0xE0) === 0x20) {
            if (($value & 0xE0) === 0x60) {
                $irq = $value & 0x7;
                $this->eoiSlave($irq);
            } else {
                $this->eoiSlave(null);
            }
            return;
        }

        if (($value & 0x68) === 0x60) {
            $this->specialMaskMode = ($value & 0x40) !== 0;
        }
    }

    public function writeDataMaster(int $value): void
    {
        $value &= 0xFF;
        if ($this->icwStepMaster === 1) {
            // ICW2: vector offset
            $this->baseMaster = $value & 0xF8;
            $this->icwStepMaster = 2;
            return;
        }
        if ($this->icwStepMaster === 2) {
            // ICW3: cascade wiring; ignore content
            $this->icwStepMaster = $this->expectIcw4Master ? 3 : 0;
            return;
        }
        if ($this->icwStepMaster === 3) {
            // ICW4
            $this->icwStepMaster = 0;
            return;
        }

        // Otherwise treat as OCW1 mask
        $this->maskMaster($value);
    }

    public function writeDataSlave(int $value): void
    {
        $value &= 0xFF;
        if ($this->icwStepSlave === 1) {
            $this->baseSlave = $value & 0xF8;
            $this->icwStepSlave = 2;
            return;
        }
        if ($this->icwStepSlave === 2) {
            $this->icwStepSlave = $this->expectIcw4Slave ? 3 : 0;
            return;
        }
        if ($this->icwStepSlave === 3) {
            $this->icwStepSlave = 0;
            return;
        }

        $this->maskSlave($value);
    }

    public function readCommandPort(bool $slave = false): int
    {
        if ($slave) {
            return $this->readIsrSlave ? $this->isrSlave : $this->irrSlave;
        }
        return $this->readIsrMaster ? $this->isrMaster : $this->irrMaster;
    }

    public function irqMasked(int $irq): bool
    {
        if ($irq < 8) {
            return (($this->imrMaster >> $irq) & 0x1) === 1;
        }
        return (($this->imrSlave >> ($irq - 8)) & 0x1) === 1;
    }

    public function pendingVector(): ?int
    {
        while (!empty($this->pendingQueue)) {
            $irq = array_shift($this->pendingQueue);
            if ($this->irqMasked($irq)) {
                continue;
            }
            if ($irq < 8) {
                $bit = 1 << $irq;
                if (($this->isrMaster & $bit) === 0) {
                    $this->irrMaster &= ~$bit;
                    $this->isrMaster |= $bit;
                    $this->irqPending[$irq] = false;
                    return $this->baseMaster + $irq;
                }
            } else {
                $slaveIrq = $irq - 8;
                $bit = 1 << $slaveIrq;
                if (($this->isrSlave & $bit) === 0) {
                    // Cascade interrupt on master IRQ2
                    $this->irrMaster &= ~0x04;
                    $this->isrMaster |= 0x04;
                    $this->irrSlave &= ~$bit;
                    $this->isrSlave |= $bit;
                    $this->irqPending[$irq] = false;
                    return $this->baseSlave + $slaveIrq;
                }
            }
        }
        return null;
    }

    public function raiseIrq0(): void
    {
        $this->raiseIrq(0);
    }

    public function raiseIrq1(): void
    {
        $this->raiseIrq(1);
    }

    public function raiseIrq(int $irq): void
    {
        if ($irq < 8) {
            $this->irrMaster |= 1 << ($irq & 0x7);
        } else {
            $this->irrSlave |= 1 << (($irq - 8) & 0x7);
            // Mark cascade request on master IRQ2
            $this->irrMaster |= 0x04;
        }
        $this->irqPending[$irq] = true;

        // Special mask mode delivers only one pending IRQ at a time.
        if ($this->specialMaskMode) {
            if (empty($this->pendingQueue)) {
                $this->pendingQueue[] = $irq;
            }
        } else {
            $this->pendingQueue[] = $irq;
        }

        $this->apicState->raiseIoapicIrq($irq);
    }
}
