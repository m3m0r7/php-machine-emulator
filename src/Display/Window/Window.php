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
    public const SDL_KEYDOWN_EVENT = 0x300;

    // SDL key modifiers
    public const KMOD_LGUI = 0x0400;  // Left Cmd key (macOS)
    public const KMOD_RGUI = 0x0800;  // Right Cmd key (macOS)
    public const KMOD_GUI = 0x0C00;   // Either Cmd key

    // SDL Mouse button masks (from SDL_GetMouseState)
    public const SDL_BUTTON_LEFT = 1;
    public const SDL_BUTTON_MIDDLE = 2;
    public const SDL_BUTTON_RIGHT = 4;

    protected FFI $ffi;
    protected mixed $window = null;
    protected mixed $renderer = null;
    protected mixed $event = null;
    protected bool $running = false;
    protected ?WindowCanvas $canvas = null;
    protected ?WindowKeyboard $keyboard = null;
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
            typedef uint16_t Uint16;
            typedef uint32_t Uint32;
            typedef uint8_t Uint8;
            typedef int32_t Sint32;
            typedef uint64_t Uint64;

            typedef struct SDL_Window SDL_Window;
            typedef struct SDL_Renderer SDL_Renderer;
            typedef struct SDL_Texture SDL_Texture;

            typedef struct SDL_Keysym {
                Uint32 scancode;
                Sint32 sym;
                Uint16 mod;
                Uint32 unused;
            } SDL_Keysym;

            typedef struct SDL_KeyboardEvent {
                Uint32 type;
                Uint32 timestamp;
                Uint32 windowID;
                Uint8 state;
                Uint8 repeat;
                Uint8 padding2;
                Uint8 padding3;
                SDL_Keysym keysym;
            } SDL_KeyboardEvent;

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

            // Keyboard state
            const Uint8* SDL_GetKeyboardState(int* numkeys);

            // Mouse state
            Uint32 SDL_GetMouseState(int* x, int* y);
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
        $this->keyboard = new WindowKeyboard($this->ffi);

        return $this;
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

            // Check for Cmd+Q (macOS) to quit
            if ($this->event->type === self::SDL_KEYDOWN_EVENT) {
                $keyEvent = $this->ffi->cast('SDL_KeyboardEvent*', FFI::addr($this->event));
                $scancode = $keyEvent->keysym->scancode;
                $mod = $keyEvent->keysym->mod;

                // Q key scancode is 20, check if GUI (Cmd) modifier is pressed
                if ($scancode === SDLScancode::Q->value && ($mod & self::KMOD_GUI) !== 0) {
                    return false;
                }
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

    /**
     * Get current mouse position and button state
     *
     * @return array{x: int, y: int, buttons: int} Mouse position and button bitmask
     */
    public function getMouseState(): array
    {
        $x = $this->ffi->new('int');
        $y = $this->ffi->new('int');
        $buttons = $this->ffi->SDL_GetMouseState(FFI::addr($x), FFI::addr($y));

        return [
            'x' => $x->cdata,
            'y' => $y->cdata,
            'buttons' => $buttons,
        ];
    }

    public function keyboard(): WindowKeyboard
    {
        if ($this->keyboard === null) {
            throw new WindowException('Window must be initialized before accessing keyboard');
        }

        return $this->keyboard;
    }

    /**
     * Check if a specific key is currently pressed
     *
     * @param SDLScancode $scancode SDL scancode
     * @return bool True if key is pressed
     */
    public function isKeyPressed(SDLScancode $scancode): bool
    {
        return $this->keyboard()->isKeyPressed($scancode);
    }

    /**
     * Get raw keyboard state array pointer
     *
     * @return FFI\CData Pointer to keyboard state array
     */
    public function getKeyboardState(): FFI\CData
    {
        return $this->keyboard()->getKeyboardState();
    }

    /**
     * Get all currently pressed keys as SDL scancodes
     *
     * @return SDLScancode[] Array of SDL scancodes that are currently pressed
     */
    public function getPressedKeys(): array
    {
        return $this->keyboard()->getPressedKeys();
    }

    /**
     * Check if shift key is currently pressed
     */
    public function isShiftPressed(): bool
    {
        return $this->keyboard()->isShiftPressed();
    }

    /**
     * Poll for a single key press and return BIOS key code
     *
     * This returns the first pressed key found and its BIOS representation.
     * For use with INT 16h AH=00h (wait for keypress)
     *
     * @return int|null AX value (AH=scan code, AL=ASCII) or null if no key pressed
     */
    public function pollKeyPress(): ?int
    {
        return $this->keyboard()->pollKeyPress();
    }
}
