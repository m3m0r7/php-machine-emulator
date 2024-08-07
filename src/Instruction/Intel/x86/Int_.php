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
use PHPMachineEmulator\Video\VideoTypeInfo;

class Int_ implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xCD];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
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

        match ($serviceFunction = VideoServiceFunction::find($fetchResult->asHighBit())) {
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

        $videoType = $fetchResult->asLowBit();

        // NOTE: validate video type
        /**
         * @var VideoTypeInfo|null $video
         */
        $video = $runtime->video()->supportedVideoModes()[$videoType] ?? null;
        if ($video === null) {
            throw new VideoInterruptException(
                'The specified video type was not supported yet (0x%02X)',
                $videoType,
            );
        }

        // NOTE: Calculate terminal (as a video mode) size
        [$height, $width] = explode(
            ' ',
            exec(
                sprintf(
                    'stty rows %d cols %d; stty size',
                    $video->height,
                    $video->width,
                )
            ),
        );

        $width = (int) $width;
        $height = (int) $height;

        if ($video->width > $width) {
            throw new VideoInterruptException(
                sprintf(
                    'The video is not enough rendering spaces of the width: not enough %d cols',
                    $video->width - $width,
                )
            );
        }

        if ($video->height > $height) {
            throw new VideoInterruptException(
                sprintf(
                    'The video is not enough rendering spaces of the height: not enough %d rows',
                    $video->height - $height,
                )
            );
        }

        $runtime->option()->logger()->debug(
            sprintf(
                'Render video size %dx%d',
                $width,
                $height,
            )
        );

        $runtime->memoryAccessor()
            ->write(
                $runtime->video()->videoTypeFlagAddress(),
                // NOTE: Actual width + Actual Height + Video Type
                (($width & 0b11111111_11111111) << 24) + (($height & 0b11111111_11111111) << 8) + $videoType,
            );
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
