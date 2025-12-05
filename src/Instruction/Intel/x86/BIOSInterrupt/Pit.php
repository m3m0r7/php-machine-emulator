<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

class Pit
{
    private int $counter0 = 0;
    private int $reload0 = 0;
    private int $mode0 = 2; // default mode 2
    private int $accessMode0 = 3; // low/high
    private bool $bcd0 = false;
    private ?int $latchedCount0 = null;
    private int $readFlipFlop0 = 0;
    private int $writeFlipFlop0 = 0;

    public function writeControl(int $value): void
    {
        $channel = ($value >> 6) & 0x3;
        if ($channel !== 0) {
            return; // only channel 0 modeled
        }

        $access = ($value >> 4) & 0x3;
        $mode = ($value >> 1) & 0x7;
        $bcd = ($value & 0x1) !== 0;

        if ($access === 0) {
            // latch count
            $this->latchedCount0 = $this->counter0;
            $this->readFlipFlop0 = 0;
            return;
        }

        $this->accessMode0 = $access;
        $this->mode0 = $mode;
        $this->bcd0 = $bcd;
        $this->writeFlipFlop0 = 0;
    }

    public function writeChannel(int $channel, int $value): void
    {
        if ($channel === 0) {
            $val = $value & 0xFF;
            if ($this->accessMode0 === 1) { // lobyte
                $this->reload0 = ($this->reload0 & 0xFF00) | $val;
                $this->loadCounter0();
            } elseif ($this->accessMode0 === 2) { // hibyte
                $this->reload0 = ($this->reload0 & 0x00FF) | ($val << 8);
                $this->loadCounter0();
            } else { // low/high
                if ($this->writeFlipFlop0 === 0) {
                    $this->reload0 = ($this->reload0 & 0xFF00) | $val;
                    $this->writeFlipFlop0 = 1;
                } else {
                    $this->reload0 = ($this->reload0 & 0x00FF) | ($val << 8);
                    $this->writeFlipFlop0 = 0;
                    $this->loadCounter0();
                }
            }
        }
    }

    public function tick(?callable $irq0 = null): void
    {
        if ($this->counter0 === 0) {
            $this->counter0 = $this->reload0 === 0 ? 0x10000 : $this->reload0;
        }
        if ($this->counter0 > 0) {
            $this->counter0--;
            if ($this->counter0 === 0) {
                error_log(sprintf('PIT: counter0 reached 0, reload0=0x%04X, calling irq0=%s', $this->reload0, $irq0 ? 'yes' : 'no'));
                if ($irq0) {
                    $irq0();
                }
                $this->loadCounter0();
            }
        }
    }

    public function readCounter(): int
    {
        $value = $this->latchedCount0 ?? $this->counter0;
        $ret = 0;
        if ($this->accessMode0 === 2) { // hibyte only
            $ret = ($value >> 8) & 0xFF;
        } elseif ($this->accessMode0 === 1) { // lobyte only
            $ret = $value & 0xFF;
        } else { // low/high
            if ($this->readFlipFlop0 === 0) {
                $ret = $value & 0xFF;
                $this->readFlipFlop0 = 1;
            } else {
                $ret = ($value >> 8) & 0xFF;
                $this->readFlipFlop0 = 0;
                $this->latchedCount0 = null;
            }
        }
        return $ret;
    }

    private function loadCounter0(): void
    {
        $this->counter0 = $this->reload0 === 0 ? 0x10000 : $this->reload0;
        $this->latchedCount0 = null;
        $this->readFlipFlop0 = 0;
    }
}
