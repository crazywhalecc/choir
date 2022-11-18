<?php

/** @noinspection PhpComposerExtensionStubsInspection */

declare(strict_types=1);
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @see    http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Choir\EventLoop;

/**
 * Ev eventloop
 */
class Ev implements EventInterface
{
    /**
     * All listeners for read event.
     */
    protected array $read_events = [];

    /**
     * All listeners for write event.
     */
    protected array $write_events = [];

    /**
     * EventLoop listeners of signal.
     */
    protected array $event_signals = [];

    /**
     * All timer event listeners.
     */
    protected array $event_timers = [];

    /**
     * Timer id.
     */
    protected static int $timer_id = 1;

    public static function isAvailable(): bool
    {
        return extension_loaded('ev');
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $timer_id = self::$timer_id;
        $event = new \EvTimer($delay, 0, function () use ($func, $args, $timer_id) {
            unset($this->event_timers[$timer_id]);
            $func(...(array) $args);
        });
        $this->event_timers[self::$timer_id] = $event;
        return self::$timer_id++;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timer_id): bool
    {
        if (isset($this->event_timers[$timer_id])) {
            $this->event_timers[$timer_id]->stop();
            unset($this->event_timers[$timer_id]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, $func, $args)
    {
        $event = new \EvTimer($interval, $interval, function () use ($func, $args) {
            $func(...(array) $args);
        });
        $this->event_timers[self::$timer_id] = $event;
        return self::$timer_id++;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $fd_key = (int) $stream;
        $event = new \EvIo($stream, \Ev::READ, function () use ($func, $stream) {
            $func($stream);
        });
        $this->read_events[$fd_key] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fd_key = (int) $stream;
        if (isset($this->read_events[$fd_key])) {
            $this->read_events[$fd_key]->stop();
            unset($this->read_events[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $fd_key = (int) $stream;
        $event = new \EvIo($stream, \Ev::WRITE, function () use ($func, $stream) {
            $func($stream);
        });
        $this->read_events[$fd_key] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fd_key = (int) $stream;
        if (isset($this->write_events[$fd_key])) {
            $this->write_events[$fd_key]->stop();
            unset($this->write_events[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        $event = new \EvSignal($signal, function () use ($func, $signal) {
            $func($signal);
        });
        $this->event_signals[$signal] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        if (isset($this->event_signals[$signal])) {
            $this->event_signals[$signal]->stop();
            unset($this->event_signals[$signal]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->event_timers as $event) {
            $event->stop();
        }
        $this->event_timers = [];
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        \Ev::run();
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        \Ev::stop();
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount(): int
    {
        return \count($this->event_timers);
    }
}
