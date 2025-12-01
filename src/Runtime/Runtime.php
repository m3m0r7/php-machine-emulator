<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Disk\Bootloader;
use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Frame\Frame;
use PHPMachineEmulator\Frame\FrameInterface;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Pit;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\ServiceInterface;
use PHPMachineEmulator\MachineInterface;
use PHPMachineEmulator\OptionInterface;
use PHPMachineEmulator\Runtime\Interrupt\InterruptDeliveryHandler;
use PHPMachineEmulator\Runtime\Interrupt\InterruptDeliveryHandlerInterface;
use PHPMachineEmulator\Runtime\Ticker\ApicTicker;
use PHPMachineEmulator\Runtime\Ticker\PitTicker;
use PHPMachineEmulator\Runtime\Ticker\TickerRegistry;
use PHPMachineEmulator\Runtime\Ticker\TickerRegistryInterface;
use PHPMachineEmulator\Stream\BootableStreamInterface;
use PHPMachineEmulator\Stream\MemoryStream;
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
    protected ?RegisterType $segmentOverride = null;
    protected int $instructionCount = 0;
    protected MemoryStream $memory;

    public function __construct(
        protected MachineInterface $machine,
        protected RuntimeOptionInterface $runtimeOption,
        protected ArchitectureProviderInterface $architectureProvider,
        protected BootableStreamInterface $bootStream,
    ) {
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
            $this->machine->option()->screenWriterFactory(),
            $this,
            $this->architectureProvider->video(),
        );
        $this->context = new RuntimeContext(
            $this->runtimeOption->cpuContext(),
            $screenContext,
        );

        // Initialize ticker registry with default tickers
        $this->tickerRegistry = new TickerRegistry();
        $this->tickerRegistry->register(new PitTicker(Pit::shared()));
        $this->tickerRegistry->register(new ApicTicker());

        // Initialize interrupt delivery handler
        $this->interruptDeliveryHandler = new InterruptDeliveryHandler($this->architectureProvider);

        if ($this->option()->shouldShowHeader()) {
            $this->showHeader();
        }

        // Convert boot stream to memory stream
        $this->memory = $this->createMemoryStream();
    }

    /**
     * Create memory stream with boot data copied in.
     */
    private function createMemoryStream(): MemoryStream
    {
        // Create memory stream (2MB to accommodate floppy images)
        $memoryStream = new MemoryStream(0x200000);

        $loadAddress = $this->bootStream->loadAddress();
        $bootSize = $this->bootStream->fileSize();

        $this->machine->option()->logger()->debug(
            sprintf('Copying boot data (%d bytes) to memory at 0x%05X', $bootSize, $loadAddress)
        );

        // Copy boot data to memory at loadAddress (e.g., 0x7C00)
        $memoryStream->copy($this->bootStream, 0, $loadAddress, $bootSize);

        // Set initial offset to boot entry point
        $memoryStream->setOffset($loadAddress);

        return $memoryStream;
    }

    /**
     * Get the memory stream.
     */
    public function memory(): MemoryStream
    {
        return $this->memory;
    }

    /**
     * Get the boot stream.
     */
    public function bootStream(): ?BootableStreamInterface
    {
        return $this->bootStream;
    }

    public function start(): void
    {
        $this->initialize();

        while (!$this->memory->isEOF()) {
            $this->instructionCount++;
            $this->tickTimers();
            $ipBefore = $this->memory->offset();
            $this->memoryAccessor->setInstructionFetch(true);
            $opcode = $this->memory->byte();
            $this->memoryAccessor->setInstructionFetch(false);

            // Debug: trace ALL opcodes with full register state
            $cf = $this->memoryAccessor->shouldCarryFlag() ? 1 : 0;
            $eax = $this->memoryAccessor->fetch(\PHPMachineEmulator\Instruction\RegisterType::EAX)->asBytesBySize(32);
            $edx = $this->memoryAccessor->fetch(\PHPMachineEmulator\Instruction\RegisterType::EDX)->asBytesBySize(32);
            $this->machine->option()->logger()->debug(sprintf(
                'EXEC: IP=0x%04X op=0x%02X CF=%d EAX=0x%08X EDX=0x%08X',
                $ipBefore, $opcode, $cf, $eax, $edx
            ));

            $result = $this->execute($opcode);
            if ($result === ExecutionStatus::EXIT) {
                $this->machine->option()->logger()->info('Exited program');
                $this->processShutdownCallbacks();

                $frameSet = $this->frame->pop();

                throw new ExitException(
                    'The executor received exit code',

                    // NOTE: Use value as a status code if appended frame is available.
                    //       If but not returned, use zero (successfully number) always.
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

        // Deliver pending interrupts (only on tick intervals)
        if ($this->instructionCount % 10 === 0) {
            $this->interruptDeliveryHandler->deliverPendingInterrupts($this);
        }
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

    public function segmentOverride(): ?RegisterType
    {
        return $this->segmentOverride;
    }

    public function setSegmentOverride(?RegisterType $segment): void
    {
        $this->segmentOverride = $segment;
    }

    public function execute(int $opcode): ExecutionStatus
    {
        // $this->machine->option()->logger()->debug(sprintf('Reached the opcode is 0x%04X', $opcode));

        $instruction = $this
            ->architectureProvider
            ->instructionList()
            ->getInstructionByOperationCode($opcode);

        // $this->machine->option()->logger()->info(sprintf('Process the instruction %s', get_class($instruction)));

        try {
            return $instruction
                ->process($this, $opcode);
        } catch (FaultException $e) {
            $this->machine->option()->logger()->error(sprintf('CPU fault: %s', $e->getMessage()));
            if ($this->interruptDeliveryHandler->raiseFault($this, $e->vector(), $this->memory->offset(), $e->errorCode())) {
                return ExecutionStatus::SUCCESS;
            }
            throw $e;
        } catch (ExecutionException $e) {
            $this->machine->option()->logger()->error(sprintf('Execution error: %s', $e->getMessage()));
            throw $e;
        } finally {
            $this->memoryAccessor
                ->enableUpdateFlags(true);
            $this->segmentOverride = null;
            $this->context->cpu()->clearTransientOverrides();
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

        foreach ([...($this->register)::map(), $this->video()->videoTypeFlagAddress()] as $address) {
            $this->memoryAccessor->allocate($address);

            $this->machine->option()->logger()->debug(sprintf('Address allocated 0x%03s', decbin($address)));
        }

        // Initialize CS register for bootable streams (e.g., El Torito ISO boot)
        // The boot code expects CS to be the load segment (e.g., 0x07C0 for 0x7C00)
        $this->initializeBootSegment();

        foreach ($this->architectureProvider->services() as $service) {
            assert($service instanceof ServiceInterface);

            $service->initialize($this);
            $this->machine->option()->logger()->debug(sprintf('Initialize %s service', get_class($service)));
        }
    }

    /**
     * Initialize CS register for bootable streams.
     */
    private function initializeBootSegment(): void
    {
        if ($this->bootStream === null) {
            return;
        }

        $loadSegment = $this->bootStream->loadSegment();
        $this->memoryAccessor->enableUpdateFlags(false)->write16Bit(RegisterType::CS, $loadSegment);
        $this->machine->option()->logger()->debug(
            sprintf('Initialized CS to 0x%04X for bootable stream', $loadSegment)
        );
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
