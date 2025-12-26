<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Collection\MemoryAccessorObserverCollection;
use PHPMachineEmulator\Collection\ServiceCollectionInterface;
use PHPMachineEmulator\Frame\FrameInterface;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\LogicBoard\LogicBoardInterface;
use PHPMachineEmulator\OptionInterface;
use PHPMachineEmulator\Runtime\AddressMapInterface;
use PHPMachineEmulator\Runtime\Interrupt\InterruptDeliveryHandlerInterface;
use PHPMachineEmulator\Runtime\MemoryAccessor;
use PHPMachineEmulator\Runtime\MemoryAccessorInterface;
use PHPMachineEmulator\Runtime\RuntimeContextInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Runtime\RuntimeOptionInterface;
use PHPMachineEmulator\Runtime\Ticker\TickerRegistryInterface;
use PHPMachineEmulator\Stream\BootableStreamInterface;
use PHPMachineEmulator\Stream\MemoryStream;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Video\VideoInterface;

class TestRuntime implements RuntimeInterface
{
    private TestRuntimeContext $context;
    private MemoryAccessor $memoryAccessor;
    private MemoryStream $memoryStream;
    private Register $register;
    private \PHPMachineEmulator\OptionInterface $option;
    private LogicBoardInterface $logicBoard;
    private BootableStreamInterface $bootStream;

    public function __construct(int $memorySize = 0x10000, ?BootableStreamInterface $bootStream = null)
    {
        $this->context = new TestRuntimeContext();
        $this->register = new Register();
        $this->option = new TestOption();
        $this->bootStream = $bootStream ?? new TestBootableStream();
        $this->logicBoard = new TestLogicBoard($this->bootStream);

        $observers = new MemoryAccessorObserverCollection();
        $this->memoryAccessor = new MemoryAccessor($this, $observers);

        // Allocate memory for registers and general use
        for ($i = 0; $i < $memorySize; $i++) {
            $this->memoryAccessor->allocate($i);
        }

        $this->memoryStream = new MemoryStream($memorySize);
    }

    public function runtimeOption(): RuntimeOptionInterface
    {
        return new TestRuntimeOption($this->context->cpu());
    }

    public function context(): RuntimeContextInterface
    {
        return $this->context;
    }

    public function cpuContext(): TestCPUContext
    {
        return $this->context->cpu();
    }

    public function start(): void
    {
        // No-op for testing
    }

    public function addressMap(): AddressMapInterface
    {
        return new TestAddressMap();
    }

    public function memoryAccessor(): MemoryAccessorInterface
    {
        return $this->memoryAccessor;
    }

    public function execute(int|array $opcodes): ExecutionStatus
    {
        return ExecutionStatus::SUCCESS;
    }

    public function shutdown(callable $callback): self
    {
        return $this;
    }

    public function register(): RegisterInterface
    {
        return $this->register;
    }

    public function frame(): FrameInterface
    {
        return new TestFrame();
    }

    public function option(): OptionInterface
    {
        return $this->option;
    }

    public function setOption(\PHPMachineEmulator\OptionInterface $option): void
    {
        $this->option = $option;
    }

    public function video(): VideoInterface
    {
        return new TestVideo();
    }

    public function memory(): MemoryStreamInterface
    {
        return $this->memoryStream;
    }

    public function bootStream(): BootableStreamInterface
    {
        return $this->bootStream;
    }

    public function logicBoard(): LogicBoardInterface
    {
        return $this->logicBoard;
    }

    public function services(): ServiceCollectionInterface
    {
        return new TestServiceCollection();
    }

    public function architectureProvider(): ArchitectureProviderInterface
    {
        return new TestArchitectureProvider();
    }

    public function interruptDeliveryHandler(): InterruptDeliveryHandlerInterface
    {
        return new TestInterruptDeliveryHandler();
    }

    public function tickerRegistry(): TickerRegistryInterface
    {
        return new TestTickerRegistry();
    }

    // ========================================
    // Test Helper Methods
    // ========================================

    public function setRegister(RegisterType $reg, int $value, int $size = 32): void
    {
        $this->memoryAccessor->writeBySize($reg, $value, $size);
    }

    public function getRegister(RegisterType $reg, int $size = 32): int
    {
        return $this->memoryAccessor->fetch($reg)->asBytesBySize($size);
    }

    public function writeMemory(int $address, int $value, int $size = 8): void
    {
        $this->memoryAccessor->writeBySize($address, $value, $size);
    }

    public function readMemory(int $address, int $size = 8): int
    {
        if ($size === 8) {
            return $this->memoryAccessor->readRawByte($address) ?? 0;
        }
        $value = 0;
        for ($i = 0; $i < $size / 8; $i++) {
            $byte = $this->memoryAccessor->readRawByte($address + $i) ?? 0;
            $value |= ($byte << ($i * 8));
        }
        return $value;
    }

    // Mode switching helpers
    public function setRealMode16(): void
    {
        $this->cpuContext()->setProtectedMode(false);
        $this->cpuContext()->setDefaultOperandSize(16);
        $this->cpuContext()->setDefaultAddressSize(16);
    }

    public function setRealMode32(): void
    {
        $this->cpuContext()->setProtectedMode(false);
        $this->cpuContext()->setDefaultOperandSize(32);
        $this->cpuContext()->setDefaultAddressSize(32);
    }

    public function setProtectedMode16(): void
    {
        $this->cpuContext()->setProtectedMode(true);
        $this->cpuContext()->setDefaultOperandSize(16);
        $this->cpuContext()->setDefaultAddressSize(16);
    }

    public function setProtectedMode32(): void
    {
        $this->cpuContext()->setProtectedMode(true);
        $this->cpuContext()->setDefaultOperandSize(32);
        $this->cpuContext()->setDefaultAddressSize(32);
    }

    // Flag helpers
    public function setCarryFlag(bool $value): void
    {
        $this->memoryAccessor->setCarryFlag($value);
    }

    public function getCarryFlag(): bool
    {
        return $this->memoryAccessor->shouldCarryFlag();
    }

    public function getZeroFlag(): bool
    {
        return $this->memoryAccessor->shouldZeroFlag();
    }

    public function getSignFlag(): bool
    {
        return $this->memoryAccessor->shouldSignFlag();
    }

    public function getOverflowFlag(): bool
    {
        return $this->memoryAccessor->shouldOverflowFlag();
    }

    public function setDirectionFlag(bool $value): void
    {
        $this->memoryAccessor->setDirectionFlag($value);
    }

    public function getDirectionFlag(): bool
    {
        return $this->memoryAccessor->shouldDirectionFlag();
    }

    public function setInterruptFlag(bool $value): void
    {
        $this->memoryAccessor->setInterruptFlag($value);
    }

    public function getInterruptFlag(): bool
    {
        return $this->memoryAccessor->shouldInterruptFlag();
    }

    public function setCpl(int $cpl): void
    {
        $this->cpuContext()->setCpl($cpl);
    }

    public function setIopl(int $iopl): void
    {
        $this->cpuContext()->setIopl($iopl);
    }
}
