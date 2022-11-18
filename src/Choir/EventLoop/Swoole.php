<?php

declare(strict_types=1);
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @see      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Choir\EventLoop;

use Choir\Server;
use Swoole\Event;
use Swoole\Process;
use Swoole\Timer;

class Swoole implements EventInterface
{
    /**
     * All listeners for read timer
     */
    protected array $_eventTimer = [];

    /**
     * All listeners for read event.
     */
    protected array $_readEvents = [];

    /**
     * All listeners for write event.
     */
    protected array $_writeEvents = [];

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $t = (int) ($delay * 1000);
        $t = max($t, 1);
        $timer_id = Timer::after($t, function () use ($func, $args, &$timer_id) {
            unset($this->_eventTimer[$timer_id]);
            try {
                $func(...(array) $args);
            } catch (\Throwable $e) {
                Server::logError(choir_exception_as_string($e));
            }
        });
        $this->_eventTimer[$timer_id] = $timer_id;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timer_id): bool
    {
        if (isset($this->_eventTimer[$timer_id])) {
            $res = Timer::clear($timer_id);
            unset($this->_eventTimer[$timer_id]);
            return $res;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, $func, $args)
    {
        if (!isset($this->mapId) || $this->mapId > \PHP_INT_MAX) {
            /* @phpstan-ignore-next-line */
            $this->mapId = 0;
        }
        $t = (int) ($interval * 1000);
        $t = max($t, 1);
        $timer_id = Timer::tick($t, function () use ($func, $args) {
            try {
                $func(...(array) $args);
            } catch (\Throwable $e) {
                Server::logError(choir_exception_as_string($e));
            }
        });
        $this->_eventTimer[$timer_id] = $timer_id;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $this->_readEvents[(int) $stream] = $stream;
        return Event::add($stream, $func, null, \SWOOLE_EVENT_READ);
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fd = (int) $stream;
        if (!isset($this->_readEvents[$fd])) {
            return;
        }
        unset($this->_readEvents[$fd]);
        if (!isset($this->_writeEvents[$fd])) {
            Event::del($stream);
            return;
        }
        Event::set($stream, null, null, \SWOOLE_EVENT_READ);
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $this->_writeEvents[(int) $stream] = $stream;
        return Event::add($stream, null, $func, \SWOOLE_EVENT_WRITE);
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fd = (int) $stream;
        if (!isset($this->_writeEvents[$fd])) {
            return;
        }
        unset($this->_writeEvents[$fd]);
        if (!isset($this->_readEvents[$fd])) {
            Event::del($stream);
            return;
        }
        Event::set($stream, null, null, \SWOOLE_EVENT_WRITE);
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func): bool
    {
        Server::logDebug('Swoole on signal ' . $signal);
        return Process::signal($signal, $func);
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal): bool
    {
        return Process::signal($signal, function () {
        });
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->_eventTimer as $timer_id) {
            Timer::clear($timer_id);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        Event::wait();
    }

    /**
     * Destroy loop.
     */
    public function stop()
    {
        Event::exit();
        // m\posix_kill(posix_getpid(), SIGTERM);
    }

    /**
     * Get timer count.
     */
    public function getTimerCount(): int
    {
        return \count($this->_eventTimer);
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('swoole');
    }
}
