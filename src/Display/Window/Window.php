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

    // SDL Mouse button masks (from SDL_GetMouseState)
    public const SDL_BUTTON_LEFT = 1;
    public const SDL_BUTTON_MIDDLE = 2;
    public const SDL_BUTTON_RIGHT = 4;

    // SDL Scancodes (commonly used keys)
    public const SDL_SCANCODE_A = 4;
    public const SDL_SCANCODE_B = 5;
    public const SDL_SCANCODE_C = 6;
    public const SDL_SCANCODE_D = 7;
    public const SDL_SCANCODE_E = 8;
    public const SDL_SCANCODE_F = 9;
    public const SDL_SCANCODE_G = 10;
    public const SDL_SCANCODE_H = 11;
    public const SDL_SCANCODE_I = 12;
    public const SDL_SCANCODE_J = 13;
    public const SDL_SCANCODE_K = 14;
    public const SDL_SCANCODE_L = 15;
    public const SDL_SCANCODE_M = 16;
    public const SDL_SCANCODE_N = 17;
    public const SDL_SCANCODE_O = 18;
    public const SDL_SCANCODE_P = 19;
    public const SDL_SCANCODE_Q = 20;
    public const SDL_SCANCODE_R = 21;
    public const SDL_SCANCODE_S = 22;
    public const SDL_SCANCODE_T = 23;
    public const SDL_SCANCODE_U = 24;
    public const SDL_SCANCODE_V = 25;
    public const SDL_SCANCODE_W = 26;
    public const SDL_SCANCODE_X = 27;
    public const SDL_SCANCODE_Y = 28;
    public const SDL_SCANCODE_Z = 29;
    public const SDL_SCANCODE_1 = 30;
    public const SDL_SCANCODE_2 = 31;
    public const SDL_SCANCODE_3 = 32;
    public const SDL_SCANCODE_4 = 33;
    public const SDL_SCANCODE_5 = 34;
    public const SDL_SCANCODE_6 = 35;
    public const SDL_SCANCODE_7 = 36;
    public const SDL_SCANCODE_8 = 37;
    public const SDL_SCANCODE_9 = 38;
    public const SDL_SCANCODE_0 = 39;
    public const SDL_SCANCODE_RETURN = 40;
    public const SDL_SCANCODE_ESCAPE = 41;
    public const SDL_SCANCODE_BACKSPACE = 42;
    public const SDL_SCANCODE_TAB = 43;
    public const SDL_SCANCODE_SPACE = 44;
    public const SDL_SCANCODE_F1 = 58;
    public const SDL_SCANCODE_F2 = 59;
    public const SDL_SCANCODE_F3 = 60;
    public const SDL_SCANCODE_F4 = 61;
    public const SDL_SCANCODE_F5 = 62;
    public const SDL_SCANCODE_F6 = 63;
    public const SDL_SCANCODE_F7 = 64;
    public const SDL_SCANCODE_F8 = 65;
    public const SDL_SCANCODE_F9 = 66;
    public const SDL_SCANCODE_F10 = 67;
    public const SDL_SCANCODE_F11 = 68;
    public const SDL_SCANCODE_F12 = 69;
    public const SDL_SCANCODE_RIGHT = 79;
    public const SDL_SCANCODE_LEFT = 80;
    public const SDL_SCANCODE_DOWN = 81;
    public const SDL_SCANCODE_UP = 82;
    public const SDL_SCANCODE_LCTRL = 224;
    public const SDL_SCANCODE_LSHIFT = 225;
    public const SDL_SCANCODE_LALT = 226;
    public const SDL_SCANCODE_RCTRL = 228;
    public const SDL_SCANCODE_RSHIFT = 229;
    public const SDL_SCANCODE_RALT = 230;

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

    /**
     * Check if a specific key is currently pressed
     *
     * @param int $scancode SDL scancode (SDL_SCANCODE_*)
     * @return bool True if key is pressed
     */
    public function isKeyPressed(int $scancode): bool
    {
        $keyboardState = $this->ffi->SDL_GetKeyboardState(null);
        return $keyboardState[$scancode] !== 0;
    }

    /**
     * Get raw keyboard state array pointer
     *
     * @return FFI\CData Pointer to keyboard state array
     */
    public function getKeyboardState(): FFI\CData
    {
        return $this->ffi->SDL_GetKeyboardState(null);
    }

    /**
     * Get all currently pressed keys as SDL scancodes
     *
     * @return int[] Array of SDL scancodes that are currently pressed
     */
    public function getPressedKeys(): array
    {
        $keyboardState = $this->ffi->SDL_GetKeyboardState(null);
        $pressed = [];

        // Check all mapped scancodes
        foreach (SDLKeyMapper::getMappedScancodes() as $scancode) {
            if ($keyboardState[$scancode] !== 0) {
                $pressed[] = $scancode;
            }
        }

        return $pressed;
    }

    /**
     * Check if shift key is currently pressed
     *
     * @return bool
     */
    public function isShiftPressed(): bool
    {
        $keyboardState = $this->ffi->SDL_GetKeyboardState(null);
        return $keyboardState[self::SDL_SCANCODE_LSHIFT] !== 0
            || $keyboardState[self::SDL_SCANCODE_RSHIFT] !== 0;
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
        $pressed = $this->getPressedKeys();
        if (empty($pressed)) {
            return null;
        }

        // Filter out modifier keys
        $modifiers = [
            self::SDL_SCANCODE_LSHIFT,
            self::SDL_SCANCODE_RSHIFT,
            self::SDL_SCANCODE_LCTRL,
            self::SDL_SCANCODE_RCTRL,
            self::SDL_SCANCODE_LALT,
            self::SDL_SCANCODE_RALT,
        ];

        foreach ($pressed as $scancode) {
            if (!in_array($scancode, $modifiers, true)) {
                return SDLKeyMapper::toBiosKeyCode($scancode, $this->isShiftPressed());
            }
        }

        return null;
    }
}
