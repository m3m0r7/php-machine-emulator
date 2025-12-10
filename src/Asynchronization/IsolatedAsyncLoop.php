<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Asynchronization;

use parallel\Runtime as ParallelRuntime;
use parallel\Channel as ParallelChannel;
use PHPMachineEmulator\Exception\IsolatedAsyncException;

class IsolatedAsyncLoop implements IsolatedAsyncLoopInterface
{
    public function __construct()
    {
        $this->parallelRuntime = new ParallelRuntime();
        $this->parallelChannel = new ParallelChannel();
    }

    public function send(SerializableAsyncMessageInterface $message): void
    {
        $this->parallelChannel->send(serialize(['signal' => IsolatedAsyncSignalEnum::MESSAGE, 'payload' => serialize($message)]));
    }


    public function close(): void
    {
        $this->parallelChannel->send(serialize(['signal' => IsolatedAsyncSignalEnum::CLOSE]));
    }

    public function start(string $cbClassString, int $interval = 10): void
    {
        if (!class_exists($cbClassString, false)) {
            throw new IsolatedAsyncException("The callback class not found: {$cbClassString}");
        }

        $this->parallelRuntime->run(function ($channel, $cbClassString, $interval) {
            while (true) {
                $recv = unserialize($channel->recv());
                $signal = $recv['signal'];
                if ($signal === IsolatedAsyncSignalEnum::CLOSE) {
                    return;
                }
                if ($signal === IsolatedAsyncSignalEnum::MESSAGE) {
                    $class = new $cbClassString();
                    if ($class instanceof IsolatedAsyncCallbackInterface) {
                        $class->process(unserialize($recv['payload']));
                    }
                    continue;
                }

                usleep($interval);
            }
        }, $this->parallelChannel, $cbClassString, $interval);
    }
}
