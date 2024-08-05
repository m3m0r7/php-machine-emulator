<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\VideoInterruptException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\BIOSInterrupt;
use PHPMachineEmulator\Instruction\Intel\ServiceFunction\VideoServiceFunction;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\MemoryAccessorFetchResultInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Int_ implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xCD];
    }

    public function process(int $opcode, RuntimeInterface $runtime): ExecutionStatus
    {
        $operand = $runtime
            ->streamReader()
            ->byte();

        // The BIOS video interrupt
        if ($operand === BIOSInterrupt::VIDEO_INTERRUPT->value) {
            $this->videoInterrupt($runtime);
            return ExecutionStatus::SUCCESS;
        }

        throw new ExecutionException('Not implemented interrupt types');
    }

    protected function videoInterrupt(RuntimeInterface $runtime): void
    {
        $runtime->option()->logger()->debug('Reached to video interruption');

        $fetchResult = $runtime->memoryAccessor()->fetch(RegisterType::EAX);
        match ($serviceFunction = VideoServiceFunction::find(($fetchResult->asByte() >> 8) & 0b11111111)) {
            VideoServiceFunction::SET_VIDEO_MODE => $this->setVideoMode($runtime, $fetchResult),
            VideoServiceFunction::TELETYPE_OUTPUT => $this->teletypeOutput($runtime, $fetchResult),

            VideoServiceFunction::SET_CURSOR_SHAPE,
            VideoServiceFunction::SET_CURSOR_POSITION,
            VideoServiceFunction::GET_CURSOR_POSITION,
            VideoServiceFunction::SELECT_ACTIVE_DISPLAY_PAGE,
            VideoServiceFunction::SET_ACTIVE_DISPLAY_PAGE,
            VideoServiceFunction::SCROLL_UP_WINDOW,
            VideoServiceFunction::SCROLL_DOWN_WINDOW,
            VideoServiceFunction::READ_CHARACTER_AND_ATTRIBUTE_AT_CURSOR_POSITION,
            VideoServiceFunction::WRITE_CHARACTER_AND_ATTRIBUTE_AT_CURSOR_POSITION,
            VideoServiceFunction::WRITE_CHARACTER_ONLY_AT_CURSOR_POSITION,
            VideoServiceFunction::SET_COLOR_PALETTE,
            VideoServiceFunction::READ_PIXEL,
            VideoServiceFunction::WRITE_PIXEL,
            VideoServiceFunction::GET_CURRENT_VIDEO_MODE => throw new VideoInterruptException(
                sprintf(
                    'An error occurred that the %s was not implemented yet (0x%02X)',
                    $serviceFunction->name,
                    $serviceFunction->value,
                ),
            ),
        };
    }

    protected function setVideoMode(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        $runtime->option()->logger()->debug('Set Video Mode');

        $videoType = $fetchResult->asByte() & 0b11111111;

        // NOTE: validate video type
        $video = $runtime->video()->supportedVideoModes()[$videoType] ?? null;
        if ($video === null) {
            throw new VideoInterruptException(
                'The specified video type was not supported yet (0x%02X)',
                $videoType,
            );
        }

        $runtime->memoryAccessor()
            ->write($runtime->video()->videoTypeFlagAddress(), $videoType);
    }

    protected function teletypeOutput(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        $runtime
            ->option()
            ->IO()
            ->output()
            ->write($fetchResult->asChar());
    }
}
