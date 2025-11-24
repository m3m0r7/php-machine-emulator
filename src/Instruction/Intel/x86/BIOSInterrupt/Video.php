<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Exception\VideoInterruptException;
use PHPMachineEmulator\Instruction\Intel\ServiceFunction\VideoServiceFunction;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\MemoryAccessorFetchResultInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

class Video implements InterruptInterface
{
    public function __construct(protected RuntimeInterface $runtime)
    {
    }

    public function process(RuntimeInterface $runtime): void
    {
        $runtime->option()->logger()->debug('Reached to video interruption');

        $fetchResult = $runtime->memoryAccessor()->fetch(RegisterType::EAX);

        match ($serviceFunction = VideoServiceFunction::from($fetchResult->asHighBit())) {
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

        $runtime->option()->logger()->debug(
            sprintf(
                'Render video size %dx%d',
                $video->width,
                $video->height,
            )
        );

        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->writeBySize(
                $runtime->video()->videoTypeFlagAddress(),
                // NOTE: Store width, height, and video type in a single flag address.
                // width: 16 bits (bits 48..63)
                // height: 16 bits (bits 32..47)
                // video type: 8 bits (bits 0..7)
                (($video->width & 0xFFFF) << 48) +
                (($video->height & 0xFFFF) << 32) +
                ($videoType & 0xFF),
                64,
            );
    }

    protected function teletypeOutput(RuntimeInterface $runtime, MemoryAccessorFetchResultInterface $fetchResult): void
    {
        $runtime
            ->option()
            ->IO()
            ->output()
            ->write($fetchResult->asLowBitChar());
    }
}
