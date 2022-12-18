<?php

/** @noinspection PhpFullyQualifiedNameUsageInspection */

declare(strict_types=1);

namespace Choir;

use Choir\Coroutine\Runtime;
use Choir\EventLoop\EventHandler;
use Choir\EventLoop\Swoole;
use Choir\Exception\ChoirException;
use Choir\Exception\NetworkException;
use Choir\Monitor\ProcessMonitor;
use Choir\Process\Forker;
use Choir\Process\Signal;
use Choir\Protocol\HttpProtocol;
use Choir\Protocol\HttpsProtocol;
use Choir\Protocol\RawTcpProtocol;
use Choir\Protocol\TextProtocol;
use Choir\Protocol\WebSocketProtocol;
use Psr\Log\LoggerInterface;
use ZM\Logger\ConsoleLogger;
use ZM\Logger\TablePrinter;

class Server extends SocketBase
{
    /** 可监听端口的容器类对象，均 use 了此 trait */
    use SocketListenTrait;

    /** @var string 启动显示的 MOTD，可修改为自己 App 的名称，可使用 Choir 版本变量 {version} */
    public static string $motd = <<<'EOF'
      ____ _           _
     / ___| |__   ___ (_)_ __
    | |   | '_ \ / _ \| | '__|
    | |___| | | | (_) | | |
     \____|_| |_|\___/|_|_|     v{version}

EOF;

    /** @var array|string[] 支持的协议，默认支持以下协议，可修改该变量插入或去掉支持的协议解析 */
    public static array $supported_protocol = [
        'ws' => WebSocketProtocol::class,
        'tcp' => RawTcpProtocol::class,
        'text' => TextProtocol::class,
        'http' => HttpProtocol::class,
        'https' => HttpsProtocol::class,
    ];

    /** @var int Server 实例的 ID */
    public int $id;

    /** @var null|LoggerInterface 日志对象，用于内部报错时输出 */
    protected static ?LoggerInterface $logger = null;

    /** @var int Choir Server 的状态 */
    protected int $status = CHOIR_PROC_NONE;

    /** @var int Choir Server Master 进程退出的 code */
    protected static int $exit_code = 0;

    /** @var float|int Choir Server 启动时间 */
    protected $start_time;

    /** @var ListenPort[] 额外监听的端口们 */
    protected array $listen_ports = [];

    /** @var null|int|resource 用于声明锁 */
    private static $lock_fd;

    /** @var null|Server 单例 Server，目前还不支持多例，以后支持 */
    private static ?Server $instance = null;

    /**
     * @throws ChoirException
     */
    public function __construct(string $protocol_name, array $settings = [])
    {
        $this->id = spl_object_id($this);
        $this->settings = $settings;
        $this->protocol_name = $protocol_name;
        // 检查 SAPI 环境，仅允许 CLI 和 Micro 环境
        if (($settings['check-sapi'] ?? true) && !in_array(PHP_SAPI, ['cli', 'micro'])) {
            throw new ChoirException('Only run in command line mode');
        }

        if (self::$instance !== null) {
            throw new ChoirException('Server cannot construct twice!');
        }
        self::$instance = $this;

        // 第一步：初始化依赖的全局变量
        $this->initConstants();

        // 初始化 Socket 监听相关的东西
        $this->initSocketListen();

        // 声明一个默认的 Logger（如果没有设置的话）
        if (static::$logger === null) {
            static::setLogger($this->settings['logger'] ?? new ConsoleLogger($this->settings['logger-level'] ?? 'info'));
            if (($this->settings['logger-level'] ?? 'info') === 'debug') {
                ConsoleLogger::$format = '[%date%] [MST] [%level%] %body%';
            }
        }

        // 记录启动时间
        $this->start_time = microtime(true);
    }

    public static function logError(string $str)
    {
        if (static::$logger) {
            static::$logger->error($str);
        }
    }

    /**
     * 在其他端口监听其他服务，返回一个监听端口对象
     *
     * @param  string                       $protocol_name 协议字符串
     * @param  array                        $settings      配置项
     * @throws ChoirException
     * @throws Exception\ValidatorException
     */
    public function listen(string $protocol_name, array $settings = []): ListenPort
    {
        $p = new ListenPort($protocol_name, $this, $settings);
        $this->listen_ports[] = $p;
        return $p;
    }

    /**
     * [MASTER] 启动 Choir Server
     *
     * @throws ChoirException
     * @throws \Throwable
     */
    public function start(): int
    {
        // 设置模式为正在启动
        $this->status = CHOIR_PROC_STARTING;

        // 设置错误输出
        static::$logger->debug('set error handler');
        \set_error_handler(function ($code, $msg, $file, $line) {
            // 这里为重写的部分
            static::$logger->critical("{$msg} in file {$file} on line {$line}");
            // 如果 return false 则错误会继续递交给 PHP 标准错误处理
            return true;
        });

        // 初始化定时器，在unix系统上如果安装了 pcntl，那么使用SIGALRM做提醒
        Timer::initMasterTimer();

        // Unix 系统下多进程，需要设置一个全局锁
        $this->initLock();

        // 如果是单进程模式
        if (PHP_OS_FAMILY === 'Windows' || ($this->settings['worker-num'] ?? 1) === 0) {
            define('CHOIR_SINGLE_MODE', true);
        } else {
            define('CHOIR_SINGLE_MODE', false);
        }

        if (PHP_OS_FAMILY === 'Windows' && ($this->settings['worker-num'] ?? 0) !== 0) {
            static::logDebug('Windows does not support multiple worker process, switching to single process mode');
        }

        // 是否展示 UI 列表（默认展示）
        if ($this->settings['display-ui'] ?? true) {
            $args = [];
            $args['working-dir'] = getcwd();
            $args['php-version'] = PHP_VERSION;
            $args['master-pid'] = getmypid();
            $args['server-listen'] = $this->protocol_name;
            foreach ($this->listen_ports as $k => $v) {
                $args['server-listen-' . ($k + 2)] = $v->protocol->getProtocolName();
            }
            $args[CHOIR_SINGLE_MODE ? 'mode' : 'worker'] = CHOIR_SINGLE_MODE ? 'single-process' : ($this->settings['worker-num'] ?? 1);
            echo str_replace('{version}', CHOIR_VERSION, static::$motd) . PHP_EOL;
            $printer = new TablePrinter($args);
            if (is_string($this->settings['display-ui-color'] ?? null)) {
                $printer->setValueColor($this->settings['display-ui-color']);
            }
            $printer->printAll();
        }

        // 守护进程模式
        if ($this->settings['daemon'] ?? false) {
            static::$logger->debug('Daemonize everything');
            Forker::daemonize();
        }

        // 安装默认的信号监听
        if (PHP_OS_FAMILY !== 'Windows' && ($this->settings['install-signal'] ?? []) !== false) {
            Signal::initDefaultSignal(CHOIR_PROCESS_MASTER, $this->settings['install-signal'] ?? []);
        }

        // 调用 process monitor 模块
        ProcessMonitor::saveMasterPid($this->settings['monitor']['process'] ?? true);

        // Unix 下多进程，取消全局锁
        $this->uninitLock();

        // 设置默认用户
        if (PHP_OS_FAMILY !== 'Windows') {
            if (extension_loaded('posix')) {
                $this->runtime_user = posix_getpwuid(posix_getuid())['name'];
            }
        }

        // 初始化，Master 下创建主 Socket（在不开启 reuse-port 模式下）
        if (!($this->settings['reuse-port'] ?? false)) {
            try {
                $this->startListen();
            } catch (NetworkException $e) {
                exit(1);
            }
        }

        // 初始化 Worker 进程，fork 的 fork，不 fork 的（windows）直接运行 Worker 进程的内容
        [$type, , $worker_id] = $this->initWorkers($this->settings['worker-num'] ?? 1);

        if ($type === CHOIR_PROCESS_MASTER) {
            $this->resetStd();              // 如果是 daemon 模式，需要将当前进程的 STDOUT 给干掉
            $this->loopMonitorWorkers();    // Master 进程的任务就是循环监听 Worker 进程保活
        } elseif ($type === CHOIR_PROCESS_WORKER) {
            $this->startWorker($worker_id); // 初始化 Worker 进程的内容
        }

        return static::$exit_code;
    }

    /**
     * [MASTER, WORKER] 重载所有 Worker 进程
     *
     * @param bool $just_kill 是否直接杀死 Worker 进程重启，默认为否（即平滑重启）
     * @noinspection PhpComposerExtensionStubsInspection
     * @throws Exception\MonitorException
     */
    public function reload(bool $just_kill = false): void
    {
        // [MASTER_WORKER] 先干掉不能重载的情况，说的就是单进程模式
        if (ProcessMonitor::getCurrentProcessType() === CHOIR_PROCESS_WORKER && getmypid() === ProcessMonitor::getMasterPid()) {
            static::$logger && static::$logger->error('Single process mode cannot reload !');
            return;
        }

        // [MASTER, WORKER] 下面是 Master 进程执行关闭的流程
        static::$logger && static::$logger->notice('Reloading server ...');

        // [MASTER, WORKER] 先标记状态
        $this->status = CHOIR_PROC_RELOADING;

        // [WORKER] Worker 进程下要做的事情
        if (ProcessMonitor::getMasterPid() !== getmypid()) {
            // 告诉 Master 进程，让 Master 进程处理
            posix_kill(ProcessMonitor::getMasterPid(), SIGUSR1);
            return;
        }

        // [MASTER] 遍历所有 Worker，并发送 SIGTERM 或 SIGKILL
        foreach (ProcessMonitor::getWorkerStates() as $worker_id => $state) {
            // 状态已被标记为退出的，就略过
            if ($state['status'] === CHOIR_PROC_STOPPED) {
                continue;
            }
            // 更新保存在监视器内的状态为 STOPPING
            ProcessMonitor::saveWorkerState($worker_id, ['status' => CHOIR_PROC_STOPPING]);
            // 第一遍退出，告诉 Worker 进程要退出，发送 SIGTERM，触发 Worker 的 WorkerStop 事件
            posix_kill($state['pid'], $just_kill ? SIGKILL : SIGTERM);
        }
        // [MASTER] 添加多个计时器，用于读秒，检查子进程是否都退出了
        for ($i = 1; $i <= ($this->settings['max-stop-wait'] ?? 2); ++$i) {
            Timer::add($i, [$this, 'checkChildRunning'], [], false);
        }
        // [MASTER] 添加一个最终的计时器，这个计时器用于杀死所有限定时间内依旧没有退出的子进程，防止将是进程残留
        Timer::add(($this->settings['max-stop-wait'] ?? 2) + 1, function () {
            foreach (ProcessMonitor::getWorkerStates() as $state) {
                if ($state['status'] !== CHOIR_PROC_STOPPED) {
                    // 超出限定时间，直接杀死，最后不留情面
                    posix_kill($state['pid'], SIGKILL);
                }
            }
        });
        // 裸调用一次，直接看看结束没
        $this->checkChildRunning();
    }

    /**
     * [MASTER, WORKER] 停止 Choir Server
     * 在 Worker 和 Master 进程调用均可
     *
     * @param int  $code      退出代码
     * @param bool $just_kill 是否直接杀死所有子进程，默认为否，即安全退出模式
     * @noinspection PhpComposerExtensionStubsInspection
     * @throws Exception\MonitorException
     * @throws \Throwable
     */
    public function stop(int $code = 0, bool $just_kill = false): void
    {
        // [MASTER, WORKER] 下面是 Master 进程执行关闭的流程
        static::$logger && static::$logger->notice('Stopping server ...');

        // [MASTER, WORKER] 先设置退出码
        static::$exit_code = $code;
        // [MASTER, WORKER] 调整为正在退出的状态
        $this->status = CHOIR_PROC_STOPPING;

        // [MASTER, WORKER] Windows 系统下直接退出
        if (PHP_OS_FAMILY === 'Windows') {
            if ($just_kill) exit($code);
            $this->exitWorker(static::$exit_code);
            return;
        }

        // [WORKER] Worker 或子进程，需要再发送信号给 Master，让 Master 来停
        if (ProcessMonitor::getMasterPid() !== getmypid()) {
            static::$logger->debug('Received stop command ');
            posix_kill(ProcessMonitor::getMasterPid(), $just_kill ? SIGQUIT : SIGTERM);
            return;
        }

        // [MASTER_WORKER] 单进程模式关闭
        if (ProcessMonitor::getCurrentProcessType() === CHOIR_PROCESS_WORKER && getmypid() === ProcessMonitor::getMasterPid()) {
            $this->exitWorker(static::$exit_code);
            return;
        }

        // [MASTER] 遍历所有 Worker，并发送 SIGTERM 或 SIGKILL
        foreach (ProcessMonitor::getWorkerStates() as $worker_id => $state) {
            // 状态已被标记为退出的，就略过
            if ($state['status'] === CHOIR_PROC_STOPPED) {
                continue;
            }
            // 更新保存在监视器内的状态为 STOPPING
            ProcessMonitor::saveWorkerState($worker_id, ['status' => CHOIR_PROC_STOPPING]);
            // 第一遍退出，告诉 Worker 进程要退出，发送 SIGTERM，触发 Worker 的 WorkerStop 事件
            posix_kill($state['pid'], $just_kill ? SIGKILL : SIGTERM);
        }
        // [MASTER] 添加多个计时器，用于读秒，检查子进程是否都退出了
        for ($i = 1; $i <= ($this->settings['max-stop-wait'] ?? 2); ++$i) {
            Timer::add($i, [$this, 'checkChildRunning'], [], false);
        }
        // [MASTER] 添加一个最终的计时器，这个计时器用于杀死所有限定时间内依旧没有退出的子进程，防止将是进程残留
        Timer::add(($this->settings['max-stop-wait'] ?? 2) + 1, function () {
            foreach (ProcessMonitor::getWorkerStates() as $state) {
                if ($state['status'] !== CHOIR_PROC_STOPPED) {
                    // 超出限定时间，直接杀死，最后不留情面
                    posix_kill($state['pid'], SIGKILL);
                }
            }
        }, [], false);
        // 裸调用一次，直接看看结束没
        $this->checkChildRunning();
    }

    /**
     * 设置 Choir 服务器默认使用的 Logger
     */
    public static function setLogger(LoggerInterface $logger): void
    {
        static::$logger = $logger;
    }

    /**
     * Choir 内部调试用的 Log 日志，如需调试 Choir 本身，请先 setLogger，后续内部会自动调用
     *
     * @param mixed $content 日志内容
     */
    public static function logDebug($content): void
    {
        if (static::$logger) {
            static::$logger->debug($content);
        }
    }

    public static function getInstance(): ?Server
    {
        return self::$instance;
    }

    /**
     * @throws \Throwable
     */
    public function exitWorker(int $code = 0): void
    {
        static::$logger->debug('exiting worker');
        $this->emitEventCallback('workerstop', $this);

        // 取消监听
        $this->stopListen();
        foreach ($this->listen_ports as $port) {
            $port->stopListen();
        }
        Server::logDebug('stopped all listens for worker');
        // 销毁事件循环
        if (EventHandler::$event !== null) {
            Server::logDebug('stopped event-loop, then will exit with ' . $code);
            EventHandler::$event->stop();
        }
        // 退出当前 Worker 进程
        try {
            if (EventHandler::$event instanceof Swoole) {
                /* @noinspection PhpComposerExtensionStubsInspection */
                posix_kill(getmypid(), SIGTERM);
            } else {
                exit($code);
            }
            /* @phpstan-ignore-next-line */
        } catch (\Throwable $e) {
            static::$exit_code = $code;
            Server::logError(choir_exception_as_string($e));
        }
    }

    /**
     * [MASTER] [TICK] 检查 Worker 是否存在
     *
     * @throws Exception\MonitorException
     * @noinspection PhpComposerExtensionStubsInspection
     */
    public function checkChildRunning(): bool
    {
        $have = false;
        foreach (ProcessMonitor::getWorkerStates() as $worker_id => $state) {
            if (!posix_kill($state['pid'], 0)) {
                ProcessMonitor::saveWorkerState($worker_id, ['status' => CHOIR_PROC_STOPPED]);
            } else {
                $have = true;
            }
        }

        // 表明没有未杀死的进程
        return $have;
    }

    /**
     * [MASTER] 启动时初始化的全局锁
     */
    protected function initLock(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            static::$logger->debug('Initializing master lock');
            $lock_file = sprintf('%s%s%s.lock', CHOIR_TMP_DIR, DIRECTORY_SEPARATOR, choir_id());
            self::$lock_fd = self::$lock_fd ?: fopen($lock_file, 'a+');
            if (self::$lock_fd) {
                flock(self::$lock_fd, LOCK_EX);
            }
        }
    }

    /**
     * [MASTER] 启动时初始化的全局锁解锁
     */
    protected function uninitLock(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            static::$logger->debug('Uninitializing master lock');
            $lock_file = sprintf('%s%s%s.lock', CHOIR_TMP_DIR, DIRECTORY_SEPARATOR, choir_id());
            self::$lock_fd = self::$lock_fd ?: fopen($lock_file, 'a+');
            if (self::$lock_fd) {
                flock(self::$lock_fd, LOCK_UN);
                fclose(self::$lock_fd);
                self::$lock_fd = null;
                clearstatcache();
                if (is_file($lock_file)) {
                    unlink($lock_file);
                }
            }
        }
    }

    /**
     * [MASTER, WORKER] 初始化 Worker 进程
     * Worker 等于 0 的时候，直接在 Master 进程下运行 Worker 进程，即只运行一个进程
     * Worker 大于 0 的时候，fork 新的进程
     * @throws ChoirException
     */
    protected function initWorkers(int $worker_num = 1): array
    {
        // Windows 下不允许多进程
        if (PHP_OS_FAMILY === 'Windows') {
            $worker_num = 0;
        }

        // 分道扬镳
        if ($worker_num > 0) {
            $current_type = Forker::forkWorkers($worker_num, $worker_id);
        } else {
            $current_type = CHOIR_PROCESS_WORKER;
            ProcessMonitor::setProcessType(CHOIR_PROCESS_WORKER, 0);
        }

        return [$current_type, $worker_num, $worker_id ?? null];
    }

    /**
     * [MASTER] daemon 环境下，重定向 Choir 的输出
     *
     * @param  bool           $exception_on_fail 当设置 stream 失败时是否抛出异常（默认为是）
     * @throws ChoirException
     */
    protected function resetStd(bool $exception_on_fail = true): void
    {
        // 非 daemon 模式，或者在 Windows 环境下不需要 reset
        if (!($this->settings['daemon'] ?? false) || PHP_OS_FAMILY === 'Windows') {
            return;
        }

        global $STDOUT, $STDERR;
        $stdout_file = $this->settings['stdout'] ?? '/dev/null';
        $handle = fopen($stdout_file, 'a');
        if ($handle) {
            unset($handle);
            \set_error_handler(function () {
            });
            if ($STDOUT) {
                fclose($STDOUT);
            }
            if ($STDERR) {
                fclose($STDERR);
            }
            if (is_resource(\STDOUT)) {
                fclose(\STDOUT);
            }
            if (is_resource(\STDERR)) {
                fclose(\STDERR);
            }
            $STDOUT = fopen($stdout_file, 'a');
            $STDERR = fopen($stdout_file, 'a');

            // Fix standard output cannot redirect of PHP 8.1.8's bug
            if (function_exists('posix_isatty') && posix_isatty(2)) {
                ob_start(function ($string) use ($stdout_file) {
                    file_put_contents($stdout_file, $string, FILE_APPEND);
                }, 1);
            }

            if (static::$logger instanceof ConsoleLogger) {
                static::setLogger(new ConsoleLogger(
                    $this->settings['logger'] ?? 'info',
                    $STDOUT,
                    false
                ));
            }
            \restore_error_handler();
            return;
        }
        if ($exception_on_fail) {
            throw new ChoirException('Can not open file as stream: ' . $stdout_file);
        }
    }

    /**
     * [MASTER] Master 进程进入循环监听 Worker
     *
     * @throws ChoirException
     * @throws \Throwable
     */
    protected function loopMonitorWorkers(): void
    {
        $this->status = CHOIR_PROC_STARTED;
        if (PHP_OS_FAMILY === 'Windows') {
            EventHandler::$event->run();
        } else {
            if (!\extension_loaded('pcntl')) {
                throw new ChoirException('Cannot loop: pcntl not found');
            }
            /* @phpstan-ignore-next-line */
            while (true) {
                // 调用信号 handler，等待信号
                pcntl_signal_dispatch();
                // 等待子进程或信号
                $status = 0;
                $pid = pcntl_wait($status, WUNTRACED);
                // 再次进入等待
                pcntl_signal_dispatch();
                // 子进程退出，重启子进程
                if ($pid > 0) {
                    // 查找哪个子进程退出了，给他复活
                    foreach (ProcessMonitor::getWorkerStates() as $worker_id => $state) {
                        if ($pid === $state['pid']) {
                            // 如果是非正常退出，则输出日志
                            if ($status !== 0 && $this->status !== CHOIR_PROC_RELOADING) {
                                /* @phpstan-ignore-next-line */
                                $loglvl = $this->status === CHOIR_PROC_STARTED ? 'critical' : 'debug';
                                static::$logger->{$loglvl}("worker #{$worker_id} [pid:{$pid}] exit with status {$status}");
                            }
                            ProcessMonitor::saveWorkerState($worker_id, ['status' => CHOIR_PROC_DIED], Server::getInstance()->settings['monitor']['process'] ?? true);

                            // 运行 onWorkerExit
                            try {
                                $this->emitEventCallback('workerexit', $this, $status, $pid);
                            } catch (\Throwable $e) {
                                static::$logger->critical('critical exception during WorkerExit !');
                                static::$logger->critical(choir_exception_as_string($e));
                            }

                            // Master 统计 Worker 重启次数
                            ProcessMonitor::addWorkerReloadTime($worker_id);

                            // 如果是运行的状态，那么直接 fork 新的
                            /* @phpstan-ignore-next-line */
                            if ($this->status === CHOIR_PROC_STARTED || $this->status === CHOIR_PROC_RELOADING) {
                                $type = Forker::forkWorker($worker_id);
                                // 重新在新的 Worker 进程执行一遍 Worker 初始化流程
                                if ($type == CHOIR_PROCESS_WORKER) {
                                    $this->startWorker($worker_id);
                                }
                            } else {
                                $this->checkChildRunning();
                            }
                        }
                    }
                }

                // 如果是退出状态，且子进程都关掉了，Master 的任务也完成了，那么执行最后的退出程序
                /* @phpstan-ignore-next-line */
                if ($this->status === CHOIR_PROC_STOPPING && ProcessMonitor::isAllWorkerStopped()) {
                    $this->exitMaster();
                    return;
                }
            }
        }
    }

    /**
     * [WORKER] Worker 进程刚 fork 完后的初始化部分
     * @throws ChoirException|\Throwable
     */
    protected function startWorker(?int $worker_id = null): void
    {
        if ($worker_id !== null) {
            static::logDebug('Worker: ' . $worker_id . ' is starting');
        }
        if (($this->settings['logger-level'] ?? 'info') === 'debug') {
            ConsoleLogger::$format = '[%date%] [#' . $worker_id . '] [%level%] %body%';
        }
        // 监听连接
        $this->startListen();
        foreach ($this->listen_ports as $port) {
            $port->startListen();
        }

        if ($worker_id !== null && PHP_OS_FAMILY !== 'Windows') {
            // 在启动状态的话，重设输出
            if ($this->status === CHOIR_PROC_STARTING) {
                static::resetStd();
            }

            // 清除所有计时器
            Timer::delAll();

            // 设置用户和用户组
            $this->initUserAndGroup();
        }

        // 正式启动 Worker 进程
        $this->status = CHOIR_PROC_STARTED;

        // 检查错误，输出错误日志，TODO
        register_shutdown_function(function () {
            if (EventHandler::$event instanceof Swoole) {
                exit(static::$exit_code);
            }
        });

        // 创建 EventLoop
        if (!EventHandler::$event) {
            EventHandler::$event = EventHandler::createEventLoop();
            $this->resumeAccept();
            foreach ($this->listen_ports as $port) {
                $port->resumeAccept();
            }
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            // 给 Worker 进程安装信号，Worker 进程默认只接收 SIGINT 和 SIGUSR1，都用于阻断，便于 Master 进程正确按照流程退出整个进程组
            /* @phpstan-ignore-next-line */
            Signal::initDefaultSignal(CHOIR_SINGLE_MODE ? CHOIR_PROCESS_MASTER : CHOIR_PROCESS_WORKER, $this->settings['install-signal'] ?? [], true);
        }

        restore_error_handler();

        Runtime::initCoroutineEnv();

        // 调用事件回调
        try {
            $this->emitEventCallback('workerstart', $this, $worker_id ?? 0);
        } catch (\Throwable $e) {
            static::$logger->emergency(choir_exception_as_string($e));
            sleep(1);
            $this->stop(250, true);
        }

        // EventLoop run！
        EventHandler::$event->run();
    }

    /**
     * [MASTER] 最后 Master 进程退出执行的内容，包括 onShutdown 事件回调
     *
     * @throws \Throwable
     */
    protected function exitMaster(bool $exit = true): void
    {
        $this->status = CHOIR_PROC_STOPPED;

        // 调用 onShutdown 回调
        if (isset($this->on_event['shutdown'])) {
            $this->emitEventCallback('shutdown');
        } else {
            static::$logger->info('Server stopped');
        }

        if ($exit) {
            exit(static::$exit_code);
        }
    }

    /**
     * 初始化设置 Worker 进程的用户和用户组（maybe need root？）
     *
     * @throws ChoirException
     */
    protected function initUserAndGroup(): void
    {
        if (!extension_loaded('posix')) {
            throw new ChoirException('init worker user and group failed: posix not installed');
        }
        // Get uid.
        $user_info = \posix_getpwnam($this->runtime_user);
        if (!$user_info) {
            static::$logger->warning("Warning: User {$this->runtime_user} not exsits");
            return;
        }
        $uid = $user_info['uid'];
        // Get gid.
        if ($this->runtime_group) {
            $group_info = \posix_getgrnam($this->runtime_group);
            if (!$group_info) {
                static::$logger->warning("Warning: Group {$this->runtime_group} not exsits");
                return;
            }
            $gid = $group_info['gid'];
        } else {
            $gid = $user_info['gid'];
        }

        // Set uid and gid.
        if ($uid !== \posix_getuid() || $gid !== \posix_getgid()) {
            if (!\posix_setgid($gid) || !\posix_initgroups($user_info['name'], $gid) || !\posix_setuid($uid)) {
                static::$logger->warning('Warning: change gid or uid fail.');
            }
        }
    }

    /**
     * 初始化部分运行时依赖的常量
     */
    private function initConstants(): void
    {
        // TCP 读取时候的 Buffer 大小
        isset($this->settings['tcp-read-buffer-size']) && define('CHOIR_TCP_READ_BUFFER_SIZE', $this->settings['tcp-read-buffer-size']);
    }
}
