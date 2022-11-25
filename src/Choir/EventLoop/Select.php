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
 * select eventloop
 */
class Select implements EventInterface
{
    /**
     * All listeners for read/write event.
     */
    protected array $read_events = [];

    /**
     * All listeners for read/write event.
     */
    protected array $write_events = [];

    protected array $except_events = [];

    /**
     * EventLoop listeners of signal.
     */
    protected array $signal_events = [];

    /**
     * Fds waiting for read event.
     */
    protected array $read_fds = [];

    /**
     * Fds waiting for write event.
     */
    protected array $write_fds = [];

    /**
     * Fds waiting for except event.
     */
    protected array $except_fds = [];

    /**
     * Timer scheduler.
     * {['data':timer_id, 'priority':run_timestamp], ..}
     */
    protected \SplPriorityQueue $scheduler;

    /**
     * All timer event listeners.
     * [[func, args, flag, timer_interval], ..]
     */
    protected array $event_timers = [];

    /**
     * Timer id.
     */
    protected int $timer_id = 1;

    /**
     * Select timeout.
     */
    protected int $select_timeout = 100000000;

    /**
     * Construct.
     */
    public function __construct()
    {
        // Init SplPriorityQueue.
        $this->scheduler = new \SplPriorityQueue();
        $this->scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args): int
    {
        $timer_id = $this->timer_id++;
        $run_time = \microtime(true) + $delay;
        $this->scheduler->insert($timer_id, -$run_time);
        $this->event_timers[$timer_id] = [$func, (array) $args];
        $select_timeout = ($run_time - \microtime(true)) * 1000000;
        $select_timeout = $select_timeout <= 0 ? 1 : (int) $select_timeout;
        if ($this->select_timeout > $select_timeout) {
            $this->select_timeout = $select_timeout;
        }
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, $func, $args): int
    {
        $timer_id = $this->timer_id++;
        $run_time = \microtime(true) + $interval;
        $this->scheduler->insert($timer_id, -$run_time);
        $this->event_timers[$timer_id] = [$func, (array) $args, $interval];
        $select_timeout = ($run_time - \microtime(true)) * 1000000;
        $select_timeout = $select_timeout <= 0 ? 1 : (int) $select_timeout;
        if ($this->select_timeout > $select_timeout) {
            $this->select_timeout = $select_timeout;
        }
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timer_id): bool
    {
        if (isset($this->event_timers[$timer_id])) {
            unset($this->event_timers[$timer_id]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $count = \count($this->read_fds);
        if ($count >= 1024) {
            echo "Warning: system call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.\n";
        } elseif (PHP_OS_FAMILY === 'Windows' && $count >= 256) {
            echo "Warning: system call select exceeded the maximum number of connections 256.\n";
        }
        $fd_key = (int) $stream;
        $this->read_events[$fd_key] = $func;
        $this->read_fds[$fd_key] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fd_key = (int) $stream;
        unset($this->read_events[$fd_key], $this->read_fds[$fd_key]);
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $count = \count($this->write_fds);
        if ($count >= 1024) {
            echo "Warning: system call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.\n";
        } elseif (PHP_OS_FAMILY === 'Windows' && $count >= 256) {
            echo "Warning: system call select exceeded the maximum number of connections 256.\n";
        }
        $fd_key = (int) $stream;
        $this->write_events[$fd_key] = $func;
        $this->write_fds[$fd_key] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fd_key = (int) $stream;
        unset($this->write_events[$fd_key], $this->write_fds[$fd_key]);
    }

    /**
     * {}
     * @param mixed $stream
     * @param mixed $func
     */
    public function onExcept($stream, $func)
    {
        $fd_key = (int) $stream;
        $this->except_events[$fd_key] = $func;
        $this->except_fds[$fd_key] = $stream;
    }

    /**
     * {}
     * @param mixed $stream
     */
    public function offExcept($stream)
    {
        $fd_key = (int) $stream;
        unset($this->except_events[$fd_key], $this->except_fds[$fd_key]);
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return null;
        }
        $this->signal_events[$signal] = $func;
        /* @noinspection PhpComposerExtensionStubsInspection */
        \pcntl_signal($signal, [$this, 'signalHandler']);
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        unset($this->signal_events[$signal]);
        /* @noinspection PhpComposerExtensionStubsInspection */
        \pcntl_signal($signal, SIG_IGN);
    }

    /**
     * Signal handler.
     */
    public function signalHandler(int $signal)
    {
        $this->signal_events[$signal]($signal);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        $this->scheduler = new \SplPriorityQueue();
        $this->scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $this->event_timers = [];
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        /* @phpstan-ignore-next-line */
        while (1) {
            if (extension_loaded('pcntl')) {
                // Calls signal handlers for pending signals
                \pcntl_signal_dispatch();
            }

            $read = $this->read_fds;
            $write = $this->write_fds;
            $except = $this->except_fds;

            if ($read || $write || $except) {
                // Waiting read/write/signal/timeout events.
                try {
                    @stream_select($read, $write, $except, 0, $this->select_timeout);
                } catch (\Throwable $e) {
                }
            } else {
                $this->select_timeout >= 1 && usleep($this->select_timeout);
            }

            if (!$this->scheduler->isEmpty()) {
                $this->tick();
            }

            foreach ($read as $fd) {
                $fd_key = (int) $fd;
                if (isset($this->read_events[$fd_key])) {
                    $this->read_events[$fd_key]($fd);
                }
            }

            foreach ($write as $fd) {
                $fd_key = (int) $fd;
                if (isset($this->write_events[$fd_key])) {
                    $this->write_events[$fd_key]($fd);
                }
            }

            foreach ($except as $fd) {
                $fd_key = (int) $fd;
                if (isset($this->except_events[$fd_key])) {
                    $this->except_events[$fd_key]($fd);
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        $this->deleteAllTimer();
        foreach ($this->signal_events as $signal => $item) {
            $this->offsignal($signal);
        }
        $this->read_fds = $this->write_fds = $this->except_fds = $this->read_events
            = $this->write_events = $this->except_events = $this->signal_events = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount(): int
    {
        return \count($this->event_timers);
    }

    /**
     * Tick for timer.
     */
    protected function tick()
    {
        $tasks_to_insert = [];
        while (!$this->scheduler->isEmpty()) {
            $scheduler_data = $this->scheduler->top();
            $timer_id = $scheduler_data['data'];
            $next_run_time = -$scheduler_data['priority'];
            $time_now = \microtime(true);
            $this->select_timeout = (int) (($next_run_time - $time_now) * 1000000);
            if ($this->select_timeout <= 0) {
                $this->scheduler->extract();

                if (!isset($this->event_timers[$timer_id])) {
                    continue;
                }

                // [func, args, timer_interval]
                $task_data = $this->event_timers[$timer_id];
                if (isset($task_data[2])) {
                    $next_run_time = $time_now + $task_data[2];
                    $tasks_to_insert[] = [$timer_id, -$next_run_time];
                } else {
                    unset($this->event_timers[$timer_id]);
                }
                try {
                    $task_data[0](...$task_data[1]);
                } catch (\Throwable $e) {
                    Server::logError(choir_exception_as_string($e));
                }
            } else {
                break;
            }
        }
        foreach ($tasks_to_insert as $item) {
            $this->scheduler->insert($item[0], $item[1]);
        }
        if (!$this->scheduler->isEmpty()) {
            $scheduler_data = $this->scheduler->top();
            $next_run_time = -$scheduler_data['priority'];
            $time_now = \microtime(true);
            $this->select_timeout = \max((int) (($next_run_time - $time_now) * 1000000), 0);
            return;
        }
        $this->select_timeout = 100000000;
    }
}
