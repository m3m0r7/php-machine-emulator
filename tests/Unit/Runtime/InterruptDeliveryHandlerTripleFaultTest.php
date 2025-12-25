<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Collection\MemoryAccessorObserverCollection;
use PHPMachineEmulator\Collection\MemoryAccessorObserverCollectionInterface;
use PHPMachineEmulator\Collection\ServiceCollectionInterface;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel\x86_64;
use PHPMachineEmulator\Runtime\InstructionExecutorInterface;
use PHPMachineEmulator\Runtime\Interrupt\InterruptDeliveryHandler;
use PHPMachineEmulator\Video\VideoInterface;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestInstructionExecutor;
use Tests\Utils\TestRuntime;
use Tests\Utils\TestServiceCollection;
use Tests\Utils\TestVideo;

final class InterruptDeliveryHandlerTripleFaultTest extends TestCase
{
    public function testEmptyIdtInLongModeBecomesTripleFault(): void
    {
        $runtime = new TestRuntime(memorySize: 0x20000);
        $runtime->cpuContext()->setLongMode(true);
        $runtime->cpuContext()->setPagingEnabled(true);
        // Allow IDT reads, but leave entries all-zero.
        $runtime->cpuContext()->setIdtr(base: 0x00000000, limit: 0x1000);

        $instructionList = new x86_64();
        $instructionList->setRuntime($runtime);

        $architectureProvider = new class ($instructionList) implements ArchitectureProviderInterface {
            public function __construct(private readonly InstructionListInterface $instructionList)
            {
            }

            public function observers(): MemoryAccessorObserverCollectionInterface
            {
                return new MemoryAccessorObserverCollection();
            }

            public function video(): VideoInterface
            {
                return new TestVideo();
            }

            public function instructionList(): InstructionListInterface
            {
                return $this->instructionList;
            }

            public function instructionExecutor(): InstructionExecutorInterface
            {
                return new TestInstructionExecutor();
            }

            public function services(): ServiceCollectionInterface
            {
                return new TestServiceCollection();
            }
        };

        $deliveryHandler = new InterruptDeliveryHandler($architectureProvider);

        $this->expectException(HaltException::class);
        $deliveryHandler->raiseFault($runtime, 0x0E, 0, 0);
    }
}

