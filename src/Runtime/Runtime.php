<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Collection\ServiceCollectionInterface;
use PHPMachineEmulator\Disk\Bootloader;
use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Frame\Frame;
use PHPMachineEmulator\Frame\FrameInterface;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\LogicBoard\LogicBoardInterface;
use PHPMachineEmulator\MachineInterface;
use PHPMachineEmulator\OptionInterface;
use PHPMachineEmulator\Runtime\Interrupt\InterruptDeliveryHandler;
use PHPMachineEmulator\Runtime\Interrupt\InterruptDeliveryHandlerInterface;
use PHPMachineEmulator\Runtime\Ticker\ApicTicker;
use PHPMachineEmulator\Runtime\Ticker\PitTicker;
use PHPMachineEmulator\Runtime\Ticker\TickerRegistry;
use PHPMachineEmulator\Runtime\Ticker\TickerRegistryInterface;
use PHPMachineEmulator\Stream\MemoryStream;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Video\VideoInterface;

class Runtime implements RuntimeInterface
{
    protected RegisterInterface $register;
    protected FrameInterface $frame;
    protected MemoryAccessorInterface $memoryAccessor;
    protected AddressMapInterface $addressMap;
    protected RuntimeContextInterface $context;
    protected TickerRegistryInterface $tickerRegistry;
    protected InterruptDeliveryHandlerInterface $interruptDeliveryHandler;
    protected array $shutdown = [];
    protected int $instructionCount = 0;
    protected MemoryStream $memory;

    public function __construct(
        protected MachineInterface $machine,
        protected RuntimeOptionInterface $runtimeOption,
        protected ArchitectureProviderInterface $architectureProvider,
    ) {
        // Apply PHP memory limit from LogicBoard
        $phpMemoryLimit = $this->logicBoard()->memory()->phpMemoryLimit();
        ini_set('memory_limit', $phpMemoryLimit);

        $this->register = $this
            ->architectureProvider
            ->instructionList()
            ->register();

        $this->frame = new Frame($this);
        $this->addressMap = new AddressMap($this);
        $this->memoryAccessor = new MemoryAccessor(
            $this,
            $this->architectureProvider
                ->observers(),
        );

        // Initialize RuntimeContext with CPU and Screen contexts
        $screenContext = new RuntimeScreenContext(
            $this->logicBoard()->display()->screenWriterFactory(),
            $this,
            $this->architectureProvider->video(),
        );
        $this->context = new RuntimeContext(
            $this->runtimeOption->cpuContext(),
            $screenContext,
        );

        // Initialize ticker registry with default tickers
        $this->tickerRegistry = new TickerRegistry();
        $this->tickerRegistry->register(new PitTicker($this->context->cpu()->pit()));
        $this->tickerRegistry->register(new ApicTicker());

        // Initialize interrupt delivery handler with interrupt sources
        $this->interruptDeliveryHandler = new InterruptDeliveryHandler($this->architectureProvider);
        $this->interruptDeliveryHandler->register(new Interrupt\ApicInterruptSource());
        $this->interruptDeliveryHandler->register(new Interrupt\PicInterruptSource());

        if ($this->option()->shouldShowHeader()) {
            $this->showHeader();
        }

        // Convert boot stream to memory stream
        $this->memory = $this->createMemoryStream();
    }

    /**
     * Get the LogicBoard from the machine.
     */
    public function logicBoard(): LogicBoardInterface
    {
        return $this->machine->logicBoard();
    }

    /**
     * Create memory stream with boot data copied in.
     */
    private function createMemoryStream(): MemoryStream
    {
        $memoryContext = $this->logicBoard()->memory();
        $bootStream = $this->logicBoard()->media()->primary()->stream();

        // Create memory stream with configurable sizes from LogicBoard's MemoryContext
        $memoryStream = new MemoryStream(
            $memoryContext->initialMemory(),
            $memoryContext->maxMemory(),
            $memoryContext->swapSize(),
        );

        $loadAddress = $bootStream->loadAddress();
        $bootSize = $bootStream->fileSize();

        $this->option()->logger()->debug(
            sprintf(
                'Memory configured: initial=%dMB max=%dMB',
                $memoryContext->initialMemory() / 0x100000,
                $memoryContext->maxMemory() / 0x100000
            )
        );
        $this->option()->logger()->debug(
            sprintf('Copying boot data (%d bytes) to memory at 0x%05X', $bootSize, $loadAddress)
        );

        // Copy boot data to memory at loadAddress (e.g., 0x7C00)
        $memoryStream->copy($bootStream, 0, $loadAddress, $bootSize);

        // Set initial offset to boot entry point
        $memoryStream->setOffset($loadAddress);

        return $memoryStream;
    }

    /**
     * Get the memory stream.
     */
    public function memory(): MemoryStreamInterface
    {
        return $this->memory;
    }

    public function start(): void
    {
        $this->initialize();

        $instructionList = $this->architectureProvider->instructionList();
        $executor = new InstructionExecutor($this, $instructionList, $this->interruptDeliveryHandler);

        $cpu = $this->context->cpu();
        $iterationContext = $cpu->iteration();

        while (!$this->memory->isEOF()) {
            $this->instructionCount++;
            $this->tickTimers();

            // Execute instruction with iteration context
            // The iterate() method will handle REP loops internally if a handler is set
            $result = $iterationContext->iterate($executor);

            // Handle prefix chain (CONTINUE means keep fetching)
            if ($result === ExecutionStatus::CONTINUE) {
                continue;
            }

            // Clear iteration context and transient overrides
            $iterationContext->clear();
            $cpu->clearTransientOverrides();

            // Handle exit/halt
            if ($result === ExecutionStatus::EXIT) {
                $this->machine->option()->logger()->info('Exited program');
                $this->processShutdownCallbacks();
                $frameSet = $this->frame->pop();
                throw new ExitException(
                    'The executor received exit code',
                    (int) $frameSet?->value() ?? 0,
                );
            }
            if ($result === ExecutionStatus::HALT) {
                $this->machine->option()->logger()->info('Halted program');
                $this->processShutdownCallbacks();
                throw new HaltException('The executor halted');
            }
        }

        $this->processShutdownCallbacks();
    }

    private function processShutdownCallbacks(): void
    {
        foreach ($this->shutdown as $index => $callback) {
            $this->machine->option()->logger()->debug(
                sprintf(
                    'Call a shutdown callback function#%d',
                    $index,
                )
            );
            $callback($this);
        }
    }

    public function shutdown(callable $callback): self
    {
        $this->shutdown[] = $callback;
        return $this;
    }

    private function tickTimers(): void
    {
        // Execute registered tickers
        $this->tickerRegistry->tick($this, $this->instructionCount);

        // Deliver pending interrupts
        $this->interruptDeliveryHandler->deliverPendingInterrupts($this);

        // Flush screen if needed (batched rendering)
        $this->context->screen()->flushIfNeeded();
    }

    public function addressMap(): AddressMapInterface
    {
        return $this->addressMap;
    }

    public function runtimeOption(): RuntimeOptionInterface
    {
        return $this->runtimeOption;
    }

    public function context(): RuntimeContextInterface
    {
        return $this->context;
    }

    public function option(): OptionInterface
    {
        return $this->machine->option();
    }

    public function memoryAccessor(): MemoryAccessorInterface
    {
        return $this->memoryAccessor;
    }

    public function register(): RegisterInterface
    {
        return $this->register;
    }

    public function frame(): FrameInterface
    {
        return $this->frame;
    }

    public function video(): VideoInterface
    {
        return $this->architectureProvider
            ->video();
    }

    public function services(): ServiceCollectionInterface
    {
        return $this->architectureProvider
            ->services();
    }

    /**
     * Execute an instruction.
     *
     * @param int|int[] $opcodes Single opcode or array of opcode bytes
     */
    public function execute(int|array $opcodes): ExecutionStatus
    {
        try {
            $instructionList = $this->architectureProvider->instructionList();
            [$instruction, $opcodeKey] = $instructionList->findInstruction($opcodes);

            try {
                return $instruction->process($this, $opcodeKey);
            } catch (FaultException $e) {
                $this->machine->option()->logger()->error(sprintf('CPU fault: %s', $e->getMessage()));
                if ($this->interruptDeliveryHandler->raiseFault($this, $e->vector(), $this->memory->offset(), $e->errorCode())) {
                    return ExecutionStatus::SUCCESS;
                }
                throw $e;
            } catch (ExecutionException $e) {
                $this->machine->option()->logger()->error(sprintf('Execution error: %s', $e->getMessage()));
                throw $e;
            }
        } catch (\Throwable $e) {
            $this->machine->option()->logger()->error(sprintf('Threw error: %s', $e));
            throw $e;
        }
    }

    protected function initialize(): void
    {
        $this
            ->addressMap
            ->register(
                $this->runtimeOption->entrypoint(),
                new Bootloader(),
            );
        $this->machine->option()->logger()->info(sprintf('Started machine emulating which entrypoint is 0x%04X', $this->runtimeOption->entrypoint()));
    }

    private function showHeader(): void
    {
        $this
            ->option()
            ->IO()
            ->output()
            ->write(<<<HEADER
             __        __                                __
            |__) |__| |__)   |\/|  _   _ |_  .  _   _   |_   _      |  _  |_  _   _
            |    |  | |      |  | (_| (_ | ) | | ) (-   |__ ||| |_| | (_| |_ (_) |

            Welcome to PHP Machine emulator. This project is experimental to implement a part of PHP-OS.
            NOTICE: We do not guarantee safety when using this. So please use at your own risk.


            HEADER);
    }
}
