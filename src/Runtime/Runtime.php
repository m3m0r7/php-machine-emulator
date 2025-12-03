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
        $maxOpcodeLength = $instructionList->getMaxOpcodeLength();

        while (!$this->memory->isEOF()) {
            $this->instructionCount++;
            $this->tickTimers();
            $ipBefore = $this->memory->offset();
            $this->memoryAccessor->setInstructionFetch(true);

            // Read first byte
            $firstByte = $this->memory->byte();
            $opcodes = [$firstByte];

            // Try to match multi-byte opcodes
            if ($maxOpcodeLength > 1 && !$this->memory->isEOF()) {
                $startPos = $this->memory->offset();
                $peekBytes = [$firstByte];

                // Peek ahead for potential multi-byte opcode
                for ($i = 1; $i < $maxOpcodeLength && !$this->memory->isEOF(); $i++) {
                    $peekBytes[] = $this->memory->byte();
                    if ($instructionList->isMultiByteOpcode($peekBytes)) {
                        $opcodes = $peekBytes;
                        break;
                    }
                }

                // If no multi-byte match found, rewind to after first byte
                if (count($opcodes) === 1) {
                    $this->memory->setOffset($startPos);
                }
            }

            $this->memoryAccessor->setInstructionFetch(false);

            // Debug: trace ALL opcodes with full register state
            $cf = $this->memoryAccessor->shouldCarryFlag() ? 1 : 0;
            $eax = $this->memoryAccessor->fetch(\PHPMachineEmulator\Instruction\RegisterType::EAX)->asBytesBySize(32);
            $edx = $this->memoryAccessor->fetch(\PHPMachineEmulator\Instruction\RegisterType::EDX)->asBytesBySize(32);
            $opcodeStr = implode(' ', array_map(fn($b) => sprintf('0x%02X', $b), $opcodes));
            $this->machine->option()->logger()->debug(sprintf(
                'EXEC: IP=0x%04X op=%s CF=%d EAX=0x%08X EDX=0x%08X',
                $ipBefore, $opcodeStr, $cf, $eax, $edx
            ));

            $result = $this->execute($opcodes);
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

    /**
     * Execute an instruction.
     *
     * @param int|int[] $opcodes Single opcode or array of opcode bytes
     */
    public function execute(int|array $opcodes): ExecutionStatus
    {
        $instructionList = $this->architectureProvider->instructionList();
        [$instruction, $opcodeKey] = $instructionList->findInstruction($opcodes);

        try {
            $result = $instruction->process($this, $opcodeKey);

            // Don't clear transient overrides for prefix instructions
            if ($result !== ExecutionStatus::CONTINUE) {
                $this->context->cpu()->clearTransientOverrides();
            }

            return $result;
        } catch (FaultException $e) {
            $this->context->cpu()->clearTransientOverrides();
            $this->machine->option()->logger()->error(sprintf('CPU fault: %s', $e->getMessage()));
            if ($this->interruptDeliveryHandler->raiseFault($this, $e->vector(), $this->memory->offset(), $e->errorCode())) {
                return ExecutionStatus::SUCCESS;
            }
            throw $e;
        } catch (ExecutionException $e) {
            $this->context->cpu()->clearTransientOverrides();
            $this->machine->option()->logger()->error(sprintf('Execution error: %s', $e->getMessage()));
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

        foreach ([...($this->register)::map(), $this->video()->videoTypeFlagAddress()] as $address) {
            $this->memoryAccessor->allocate($address);

            $this->machine->option()->logger()->debug(sprintf('Address allocated 0x%03s', decbin($address)));
        }

        // Initialize CS register for bootable streams (e.g., El Torito ISO boot)
        // The boot code expects CS to be the load segment (e.g., 0x07C0 for 0x7C00)
        $this->initializeBootSegment();

        // Initialize BIOS Data Area (BDA) at 0x400-0x4FF
        $this->initializeBDA();

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
        $loadSegment = $this->logicBoard()->media()->primary()->stream()->loadSegment();
        $this->memoryAccessor->write16Bit(RegisterType::CS, $loadSegment);
        $this->machine->option()->logger()->debug(
            sprintf('Initialized CS to 0x%04X for bootable stream', $loadSegment)
        );
    }

    /**
     * Initialize BIOS Data Area (BDA) at 0x400-0x4FF.
     * This area contains essential BIOS state information.
     */
    private function initializeBDA(): void
    {
        // Get default video mode info
        $videoMode = $this->video()->supportedVideoModes()[0x03] ?? null;
        $cols = $videoMode?->width ?? 80;
        $rows = $videoMode?->height ?? 25;

        // 0x449: Current video mode (default: mode 3 = 80x25 text)
        $this->memoryAccessor->writeBySize(0x449, 0x03, 8);

        // 0x44A-0x44B: Number of screen columns (word)
        $this->memoryAccessor->writeBySize(0x44A, $cols, 16);

        // 0x44C-0x44D: Size of video regen buffer in bytes
        $this->memoryAccessor->writeBySize(0x44C, $cols * $rows * 2, 16);

        // 0x44E-0x44F: Offset of current video page in video regen buffer
        $this->memoryAccessor->writeBySize(0x44E, 0x0000, 16);

        // 0x450-0x45F: Cursor position for pages 0-7 (row, col pairs)
        for ($i = 0; $i < 16; $i++) {
            $this->memoryAccessor->writeBySize(0x450 + $i, 0x00, 8);
        }

        // 0x460-0x461: Cursor shape (start/end scan lines)
        $this->memoryAccessor->writeBySize(0x460, 0x0607, 16);

        // 0x462: Current video page number
        $this->memoryAccessor->writeBySize(0x462, 0x00, 8);

        // 0x463-0x464: Base I/O port for video (0x3D4 for color, 0x3B4 for mono)
        $this->memoryAccessor->writeBySize(0x463, 0x03D4, 16);

        // 0x484: Number of rows - 1 (byte, EGA/VGA only)
        $this->memoryAccessor->writeBySize(0x484, $rows - 1, 8);

        // 0x485-0x486: Character height in scan lines (word, EGA/VGA)
        $this->memoryAccessor->writeBySize(0x485, 16, 16);

        $this->machine->option()->logger()->debug(
            sprintf('Initialized BDA: cols=%d, rows=%d', $cols, $rows)
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
