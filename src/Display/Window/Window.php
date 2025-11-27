<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Window;

use Closure;
use FFI;
use PHPMachineEmulator\Display\Pixel\Color;
use PHPMachineEmulator\Exception\WindowException;

class Window
{
    public const SDL_QUIT_EVENT = 0x100;

    protected FFI $ffi;
    protected mixed $window = null;
    protected mixed $renderer = null;
    protected mixed $event = null;
    protected bool $running = false;
    protected ?WindowCanvas $canvas = null;
    protected WindowOption $option;

    public function __construct(
        protected string $title,
        ?WindowOption $option = null,
    ) {
        $this->option = $option ?? new WindowOption();

        if (!class_exists('FFI')) {
            throw new WindowException('FFI extension is not available');
        }

        $sdlLibraryPath = $this->option->resolveSDLLibraryPath();

        $this->ffi = FFI::cdef($this->getSDLDefinitions(), $sdlLibraryPath);
    }

    public function canvas(): WindowCanvas
    {
        if ($this->canvas === null) {
            throw new WindowException('Window must be initialized before accessing canvas');
        }

        return $this->canvas;
    }

    public function option(): WindowOption
    {
        return $this->option;
    }

    protected function getSDLDefinitions(): string
    {
        return <<<CDEF
            typedef uint32_t Uint32;
            typedef uint8_t Uint8;
            typedef int32_t Sint32;
            typedef uint64_t Uint64;

            typedef struct SDL_Window SDL_Window;
            typedef struct SDL_Renderer SDL_Renderer;
            typedef struct SDL_Texture SDL_Texture;

            typedef struct SDL_Event {
                Uint32 type;
                Uint8 padding[56];
            } SDL_Event;

            typedef struct SDL_Rect {
                int x, y;
                int w, h;
            } SDL_Rect;

            int SDL_Init(Uint32 flags);
            void SDL_Quit(void);
            const char* SDL_GetError(void);

            SDL_Window* SDL_CreateWindow(
                const char* title,
                int x, int y,
                int w, int h,
                Uint32 flags
            );
            void SDL_DestroyWindow(SDL_Window* window);

            SDL_Renderer* SDL_CreateRenderer(
                SDL_Window* window,
                int index,
                Uint32 flags
            );
            void SDL_DestroyRenderer(SDL_Renderer* renderer);

            int SDL_SetRenderDrawColor(SDL_Renderer* renderer, Uint8 r, Uint8 g, Uint8 b, Uint8 a);
            int SDL_RenderClear(SDL_Renderer* renderer);
            void SDL_RenderPresent(SDL_Renderer* renderer);
            int SDL_RenderDrawPoint(SDL_Renderer* renderer, int x, int y);
            int SDL_RenderFillRect(SDL_Renderer* renderer, const SDL_Rect* rect);

            int SDL_PollEvent(SDL_Event* event);
            void SDL_Delay(Uint32 ms);
            void SDL_SetWindowSize(SDL_Window* window, int w, int h);
        CDEF;
    }

    public function initialize(): self
    {
        if ($this->ffi->SDL_Init($this->option->sdlInitVideo) < 0) {
            throw new WindowException('SDL_Init failed: ' . $this->ffi->SDL_GetError());
        }

        $this->window = $this->ffi->SDL_CreateWindow(
            $this->title,
            $this->option->sdlWindowPosX,
            $this->option->sdlWindowPosY,
            $this->option->width,
            $this->option->height,
            $this->option->sdlWindowFlags
        );

        if ($this->window === null) {
            $this->ffi->SDL_Quit();
            throw new WindowException('SDL_CreateWindow failed: ' . $this->ffi->SDL_GetError());
        }

        $this->renderer = $this->ffi->SDL_CreateRenderer($this->window, -1, $this->option->sdlRendererFlags);

        if ($this->renderer === null) {
            $this->ffi->SDL_DestroyWindow($this->window);
            $this->ffi->SDL_Quit();
            throw new WindowException('SDL_CreateRenderer failed: ' . $this->ffi->SDL_GetError());
        }

        $this->event = $this->ffi->new('SDL_Event');

        $this->canvas = new WindowCanvas($this, $this->ffi, $this->renderer);

        return $this;
    }

    public function start(): void
    {
        $this->running = true;
        $frameDelay = (int) (1000 / $this->option->frameRate);

        while ($this->running) {
            while ($this->ffi->SDL_PollEvent(FFI::addr($this->event))) {
                if ($this->event->type === self::SDL_QUIT_EVENT) {
                    $this->running = false;
                }
            }

            if (!$this->running) {
                break;
            }

            $this->canvas->clear(Color::asBlack());
            $this->canvas->render();
            $this->canvas->present();

            $this->ffi->SDL_Delay($frameDelay);
        }

        $this->destroy();
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function processEvents(): bool
    {
        while ($this->ffi->SDL_PollEvent(FFI::addr($this->event))) {
            if ($this->event->type === self::SDL_QUIT_EVENT) {
                return false;
            }
        }
        return true;
    }

    public function width(): int
    {
        return $this->option->width;
    }

    public function height(): int
    {
        return $this->option->height;
    }

    public function resize(int $width, int $height): void
    {
        $this->option = new WindowOption(
            width: $width,
            height: $height,
            frameRate: $this->option->frameRate,
            sdlInitVideo: $this->option->sdlInitVideo,
            sdlWindowPosX: $this->option->sdlWindowPosX,
            sdlWindowPosY: $this->option->sdlWindowPosY,
            sdlWindowFlags: $this->option->sdlWindowFlags,
            sdlRendererFlags: $this->option->sdlRendererFlags,
        );

        if ($this->window !== null) {
            $this->ffi->SDL_SetWindowSize($this->window, $width, $height);
        }
    }

    protected function destroy(): void
    {
        if ($this->renderer !== null) {
            $this->ffi->SDL_DestroyRenderer($this->renderer);
            $this->renderer = null;
        }

        if ($this->window !== null) {
            $this->ffi->SDL_DestroyWindow($this->window);
            $this->window = null;
        }

        $this->ffi->SDL_Quit();
    }

    public function __destruct()
    {
        if ($this->running) {
            $this->destroy();
        }
    }
}
