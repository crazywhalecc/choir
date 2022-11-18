<?php

declare(strict_types=1);
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright walkor<walkor@workerman.net>
 * @see      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Choir\EventLoop;

use Choir\Server;

/**
 * libevent eventloop
 */
class Event implements EventInterface
{
    /**
     * EventLoop base.
     * @var object
     */
    protected $event_base;

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
    protected array $event_signal = [];

    /**
     * All timer event listeners.
     * [func, args, event, flag, time_interval]
     */
    protected array $event_timer = [];

    /**
     * Timer id.
     */
    protected int $timer_id = 0;

    /**
     * EventLoop class name.
     */
    protected string $event_class_name = '';

    /**
     * Construct.
     */
    public function __construct()
    {
        if (\class_exists('\\\\EventLoop', false)) {
            $class_name = '\\\\EventLoop';
        } else {
            $class_name = '\Event';
        }
        $this->event_class_name = $class_name;
        if (\class_exists('\\\\EventBase', false)) {
            $class_name = '\\\\EventBase';
        } else {
            $class_name = '\EventBase';
        }
        $this->event_base = new $class_name();
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('event');
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $class_name = $this->event_class_name;
        $timer_id = $this->timer_id++;
        $event = new $class_name($this->event_base, -1, $class_name::TIMEOUT, function () use ($func, $args, $timer_id) {
            try {
                $this->deleteTimer($timer_id);
                $func(...$args);
            } catch (\Throwable $e) {
                Server::logError(choir_exception_as_string($e));
            }
        });
        /* @phpstan-ignore-next-line */
        if (!$event || !$event->addTimer($delay)) {
            return false;
        }
        $this->event_timer[$timer_id] = $event;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timer_id): bool
    {
        if (isset($this->event_timer[$timer_id])) {
            $this->event_timer[$timer_id]->del();
            unset($this->event_timer[$timer_id]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, $func, $args)
    {
        $class_name = $this->event_class_name;
        $timer_id = $this->timer_id++;
        $event = new $class_name($this->event_base, -1, $class_name::TIMEOUT | $class_name::PERSIST, function () use ($func, $args) {
            try {
                $func(...$args);
            } catch (\Throwable $e) {
                Server::logError(choir_exception_as_string($e));
            }
        });
        /* @phpstan-ignore-next-line */
        if (!$event || !$event->addTimer($interval)) {
            return false;
        }
        $this->event_timer[$timer_id] = $event;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func): bool
    {
        $class_name = $this->event_class_name;
        $fd_key = (int) $stream;
        $event = new $this->event_class_name($this->event_base, $stream, $class_name::READ | $class_name::PERSIST, $func, $stream);
        /* @phpstan-ignore-next-line */
        if (!$event || !$event->add()) {
            return false;
        }
        $this->write_events[$fd_key] = $event;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fd_key = (int) $stream;
        if (isset($this->read_events[$fd_key])) {
            $this->read_events[$fd_key]->del();
            unset($this->read_events[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $class_name = $this->event_class_name;
        $fd_key = (int) $stream;
        $event = new $this->event_class_name($this->event_base, $stream, $class_name::WRITE | $class_name::PERSIST, $func, $stream);
        /* @phpstan-ignore-next-line */
        if (!$event || !$event->add()) {
            return false;
        }
        $this->write_events[$fd_key] = $event;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fd_key = (int) $stream;
        if (isset($this->write_events[$fd_key])) {
            $this->write_events[$fd_key]->del();
            unset($this->write_events[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func): bool
    {
        $class_name = $this->event_class_name;
        $fd_key = (int) $signal;
        /* @phpstan-ignore-next-line */
        if (method_exists($class_name, 'signal')) {
            $event = $class_name::signal($this->event_base, $signal, $func);
            if (!$event || !$event->add()) {
                return false;
            }
            $this->event_signal[$fd_key] = $event;
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        $fd_key = (int) $signal;
        if (isset($this->event_signal[$fd_key])) {
            $this->event_signal[$fd_key]->del();
            unset($this->event_signal[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->event_timer as $event) {
            $event->del();
        }
        $this->event_timer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->event_base->loop();
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        $this->event_base->exit();
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount(): int
    {
        return \count($this->event_timer);
    }
}
