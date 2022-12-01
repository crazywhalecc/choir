<?php

declare(strict_types=1);

namespace Choir\Protocol;

use Choir\Exception\ProtocolException;
use Choir\Exception\ValidatorException;
use Choir\Http\HttpFactory;
use Choir\Http\UploadedFile;
use Choir\ListenPort;
use Choir\Protocol\Context\DefaultContext;
use Choir\Server;
use Choir\SocketBase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP 协议的实现
 */
class HttpProtocol implements TcpProtocolInterface
{
    /**
     * 默认返回的裸回包（TCP 层面出现问题时返回）
     *
     * @var array|string[]
     */
    public static array $default_raw_response = [
        200 => "HTTP/1.1 200 OK\r\n\r\n",
        400 => "HTTP/1.1 400 Bad Request\r\n\r\n",
        413 => "HTTP/1.1 413 Payload Too Large\r\n\r\n",
    ];

    /**
     * @var bool Header 识别时是否大小写敏感
     */
    public static bool $header_case_sensitive = false;

    /**
     * @var bool 是否启用 Request 对象缓存
     */
    public static bool $enable_cache = true;

    /**
     * @var int 一个 form-data 最多传输的文件个数
     */
    public static int $max_form_data_files = 1024;

    /**
     * @var string 地址
     */
    protected string $host;

    /**
     * @var int 端口
     */
    protected int $port;

    /**
     * @var string 协议字串
     */
    protected string $protocol_name;

    /**
     * @var array<string, ServerRequestInterface> 请求对象的小请求缓存列表
     */
    private static array $requests_cache = [];

    public function __construct(string $host, int $port, string $protocol_name)
    {
        $this->protocol_name = $protocol_name;
        $this->port = $port;
        $this->host = $host;
        // Choir HTTP 临时上传的目录
        if (!defined('CHOIR_UPLOAD_TMP_DIR')) {
            if ($tmpdir = ini_get('upload_tmp_dir')) {
                define('CHOIR_UPLOAD_TMP_DIR', $tmpdir);
            } elseif ($tmpdir = sys_get_temp_dir()) {
                define('CHOIR_UPLOAD_TMP_DIR', $tmpdir);
            }
        }
    }

    /**
     * 解析 Header 一行，返回 Key，Value
     *
     * @param string $content 头内容
     */
    public static function parseHeaderLine(string $content): array
    {
        $split = \explode(':', $content, 2);
        return [strtolower($split[0]), ltrim($split[1] ?? '')];
    }

    /**
     * 解析 HTTP 请求包，返回一个符合 PSR-7 的 ServerRequest 对象
     *
     * 解析失败则抛出 ProtocolException 异常
     *
     * @param  string             $raw 生数据
     * @throws ProtocolException
     * @throws ValidatorException
     */
    public static function parseRawRequest(string $raw): ServerRequestInterface
    {
        // 标记缓存
        static $header_cache = [];

        // 解析 HTTP 协议第一行
        $first_line = \strstr($raw, "\r\n", true);
        $tmp = \explode(' ', $first_line, 3);

        // 解析请求方法
        $request_method = $tmp[0];

        // 解析 URI
        $uri = $tmp[1] ?? '/';

        // 解析版本
        $version = \explode('/', $tmp[2])[1] ?? '1.1';

        // 解析 Headers
        $headers = [];
        // 先找到包含所有 Header 和 HTTP 第一行的内容
        $tmp_buffer = \strstr($raw, "\r\n\r\n", true);
        // 然后找到第一行末尾的位置
        $first_line_end = strpos($tmp_buffer, "\r\n");
        // 找不到就炸掉
        if ($first_line_end === false) {
            throw new ProtocolException('HTTP raw content is broken');
        }
        // 包含所有 Header 的内容
        $header_buffer = substr($tmp_buffer, $first_line_end + 2);
        // 读缓存，不超过 2048Byte 的都可以缓存
        $cache = static::$enable_cache && !isset($tmp_buffer[2048]);
        if ($cache && isset($header_cache[$tmp_buffer])) {
            $headers = $header_cache[$tmp_buffer];
        } else {
            // 没缓存，则继续解析
            $header_data = \explode("\r\n", $header_buffer);
            foreach ($header_data as $content) {
                [$key, $value] = static::parseHeaderLine($content);
                $headers[$key][] = $value;
            }

            // 可以缓存的话缓存一下
            if ($cache) {
                $header_cache[$tmp_buffer] = $headers;
                if (\count($header_cache) > 128) {
                    unset($header_cache[key($header_cache)]);
                }
            }
        }

        // 解析 Body
        $body = substr($raw, \strpos($raw, "\r\n\r\n") + 4);

        // 生成对象
        $request = HttpFactory::createServerRequest(
            $request_method,
            $uri,
            $headers,
            $body,
            $version,
            []
        );

        // 解析 GET 参数
        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);

        // 解析 POST 参数（如果 Content-Type 是 application/x-www-form-urlencoded 或 multipart/form-data）
        if ($request->getMethod() === 'POST') {
            $content_type = \explode(';', $request->getHeaderLine('Content-Type'));
            switch ($content_type[0]) {
                case 'application/x-www-form-urlencoded':
                    // TODO：以后让 Choir 支持别的编码，例如 GBK
                    parse_str($body, $parse);
                    $request = $request->withParsedBody($parse);
                    break;
                case 'multipart/form-data':
                    $boundary = ltrim($content_type[1] ?? '');
                    // 如果form-data不包含 boundary，就炸掉
                    if (!str_starts_with($boundary, 'boundary=')) {
                        throw new ProtocolException('multipart/form-data does not specify boundary');
                    }
                    $boundary = substr($boundary, 9);
                    if ($boundary === '') {
                        throw new ProtocolException('Bounadry cannot be empty');
                    }

                    // 解析 form-data，太多了，独立个函数吧
                    [$files, $post] = static::parseFormData($boundary, $body);

                    if ($files !== []) {
                        $request = $request->withUploadedFiles(array_map(fn ($file) => new UploadedFile($file), $files));
                    }
                    if ($post !== []) {
                        $request = $request->withParsedBody($post);
                    }
                    break;
            }
        }

        return $request;
    }

    /**
     * 解析 form-data
     *
     * @param string $boundary boundary
     * @param string $body     请求过来的 Body Stream
     */
    public static function parseFormData(string $boundary, string $body): array
    {
        // RFC-9110
        $boundary = '--' . $boundary;
        $boundary_sig = "\r\n" . $boundary;

        // POST 编码后的串
        $post_encode_string = '';
        // file_encode_str
        $files_encode_str = '';

        // POST 编码后的串
        // file_encode_str

        // 所有文件
        $files = [];
        $offset = 0;
        $max_file_count = static::$max_form_data_files;
        // 遍历
        while ($max_file_count-- > 0 && $offset >= 0) {
            $file = [];
            // 如果偏移到外面了，那就停
            if (\strlen($body) < $offset) {
                $offset = -1;
                continue;
            }
            // 找到当前 Section，如果不等于 boundary，那我就认为这个是个坏的 form-data
            $section_end_offset = strpos($body, $boundary_sig, $offset);
            if (!$section_end_offset) {
                $offset = -1;
                continue;
                // throw new ProtocolException('boundary is broken');
                // return 0;
            }
            // 找一下 Header 的末尾位置
            $content_lines_end_offset = strpos($body, "\r\n\r\n", $offset);
            // 没找到 Header 末尾位置，或者 Header 末尾位置超过了整个 Section 的长度
            if (!$content_lines_end_offset || $content_lines_end_offset + 4 > $section_end_offset) {
                $offset = -1;
                continue;
                // throw new ProtocolException('form-data section is broken');
            }
            $upload_key = false;
            // 找到 Header 子串
            $content_lines_str = substr($body, $offset, $content_lines_end_offset - $offset);
            // 切割每行
            $content_lines = \explode("\r\n", trim($content_lines_str . "\r\n"));
            // 内容
            $boundary_value = substr($body, $content_lines_end_offset + 4, $section_end_offset - $content_lines_end_offset - 4);
            // 解析 Header
            foreach ($content_lines as $content_line) {
                [$key, $value] = static::parseHeaderLine($content_line);
                switch ($key) {
                    case 'content-disposition':
                        // 解析 Content-Disposition
                        $exp = \explode(';', $value, 2);
                        if (trim($exp[0]) !== 'form-data') {
                            $offset = -1;
                            continue 3;
                            // throw new ProtocolException('section\'s Content-Disposition must be form-data !');
                        }
                        // 这是个文件
                        if (preg_match('/name="(.*?)"; filename="(.*?)"/i', $value, $match)) {
                            $error = 0;
                            $tmp_file = '';
                            $size = \strlen($boundary_value);
                            $tmp_upload_dir = defined('CHOIR_UPLOAD_TMP_DIR') ? CHOIR_UPLOAD_TMP_DIR : null;
                            if (!$tmp_upload_dir) {
                                $error = UPLOAD_ERR_NO_TMP_DIR;
                            } else {
                                $tmp_file = \tempnam($tmp_upload_dir, 'choir.upload.');
                                if ($tmp_file === false || !\file_put_contents($tmp_file, $boundary_value)) {
                                    $error = UPLOAD_ERR_CANT_WRITE;
                                }
                            }
                            $upload_key = $match[1];
                            // Parse upload files.
                            $file = [
                                'key' => $match[1],
                                'name' => $match[2],
                                'tmp_name' => $tmp_file,
                                'size' => $size,
                                'error' => $error,
                                'type' => null,
                            ];
                            break;
                        }
                        if (preg_match('/name="(.*?)"$/', $value, $match)) {
                            // 剩下的当作 POST 参数来解析
                            $k = $match[1];
                            $post_encode_string .= \urlencode($k) . '=' . \urlencode($boundary_value) . '&';
                        }
                        $offset = $section_end_offset + \strlen($boundary_sig) + 2;
                        continue 3;
                    case 'content-type':
                        $file['type'] = trim($value);
                        break;
                }
            }

            if ($upload_key === false) {
                break;
            }

            $files_encode_str .= \urlencode($upload_key) . '=' . \count($files) . '&';
            $files[] = $file;

            $offset = $section_end_offset + \strlen($boundary_sig) + 2;
        }

        // 解析出来的 POST 字串
        if ($post_encode_string) {
            parse_str($post_encode_string, $parse);
        }

        if ($files_encode_str) {
            parse_str($files_encode_str, $parse2);
            \array_walk_recursive($parse2, function (&$value) use ($files) {
                $value = $files[$value];
            });
        }
        return [$parse2 ?? [], $parse ?? []];
    }

    /**
     * @throws \Throwable
     */
    public function checkPackageLength(string $buffer, ConnectionInterface $connection): int
    {
        // 本地静态变量做缓存
        static $input = [];

        // 不要把 UDP 连接传进来！
        if (!$connection instanceof Tcp) {
            return 0;
        }

        // 小于 512Byte 的包将被缓存，但是 input 最多缓存 500M（65536）个小包
        if (!isset($buffer[512]) && isset($input[$buffer])) {
            return $input[$buffer];
        }

        // 如果是 Client Mode，就给它转到
        if ($connection->isClientMode()) {
            return $this->checkResponsePackageLength($buffer, $connection);
        }

        // 查找 Header Body 分隔的位置
        $crlf_pos = strpos($buffer, "\r\n\r\n");
        if ($crlf_pos === false) {
            // 一个包过大，则会出现问题
            if (\strlen($buffer) >= 16384) {
                $connection->close(static::$default_raw_response[413]);
                return 0;
            }
            // Header 部分没传完，接着传
            return 0;
        }

        // 头长度
        $length = $crlf_pos + 4;
        // HTTP 协议第一行，包含 GET 路径和参数以及 HTTP 协议版本及方法
        [$request_method, , $http_version] = \explode(' ', \strstr($buffer, "\r\n", true), 3);

        // 限定请求方法
        if (!in_array($request_method, ['GET', 'POST', 'OPTIONS', 'HEAD', 'DELETE', 'PUT', 'PATCH'])) {
            $connection->close(static::$default_raw_response[400]);
            return 0;
        }

        // 寻找 Host 字段
        $header = substr($buffer, 0, $crlf_pos);
        $host_location = static::$header_case_sensitive ? strpos($header, "\r\nHost: ") : stripos($header, "\r\nHost: ");
        if ($host_location === false && $http_version === 'HTTP/1.1') {
            $connection->close(static::$default_raw_response[400]);
        }

        // 识别 Content-Length 头
        if ($pos = (static::$header_case_sensitive ? 'strpos' : 'stripos')($header, "\r\nContent-Length: ")) {
            // 最大单包为 2147483647，十位够了
            $length = $length + (int) substr($header, $pos + 18, 10);
            $has_content_length = true;
        } else {
            $has_content_length = false;
            if ((static::$header_case_sensitive ? 'strpos' : 'stripos')($header, "\r\nTransfer-Encoding: ") !== false) {
                $connection->close(static::$default_raw_response[400]);
                return 0;
            }
        }

        // 提供了 Content-Length，就按照 Content-Length 来读取包长度
        if ($has_content_length) {
            if ($length > $connection->getMaxPackageSize()) {
                $connection->close(static::$default_raw_response[413]);
                return 0;
            }
        }

        // 如果小于 512Byte，那么缓存一下
        if (!isset($buffer[512])) {
            $input[$buffer] = $length;
            if (\count($input) > 512) {
                unset($input[key($input)]);
            }
        }

        return $length;
    }

    /**
     * @param  ListenPort|Server            $server 协议操作对象
     * @throws ValidatorException
     * @throws ProtocolException|\Throwable
     */
    public function execute($server, string $package, ConnectionInterface $connection): bool
    {
        // 不要把 UDP 连接传进来！
        if (!$connection instanceof Tcp) {
            return false;
        }

        // client 模式，就解析的是 Response
        if ($connection->isClientMode()) {
            return $this->executeResponse($server, $package, $connection);
        }

        // 先检查缓存
        $cache = static::$enable_cache && !isset($package[512]);
        if ($cache && isset(self::$requests_cache[$package])) {
            $request = self::$requests_cache[$package];
            Server::logDebug('Using request cache: ' . strlen($package));
            // 执行 HTTP Request 回调
            $server->emitEventCallback('request', $connection, $request);
            return true;
        }

        // 不是缓存，现在生成
        $request = static::parseRawRequest($package);
        $server->emitEventCallback('request', $connection, $request);
        // 缓存一下
        if ($cache) {
            Server::logDebug('Caching request cache: ' . strlen($package));
            self::$requests_cache[$package] = $request;
            if (count(self::$requests_cache) > 512) {
                unset(self::$requests_cache[key(self::$requests_cache)]);
            }
        }

        return true;
    }

    public function getSocketAddress(): string {return 'tcp://' . $this->host . ':' . $this->port; }

    public function getTransport(): string {return 'tcp'; }

    public function getBuiltinTransport(): string {return 'tcp'; }

    public function getProtocolEvents(): array {return ['request']; }

    public function getProtocolName(): string {return $this->protocol_name; }

    public function makeContext(): object {return new DefaultContext(); }

    public function getConnectionClass(): string {return HttpConnection::class; }

    private function checkResponsePackageLength(string $buffer, Tcp $connection): int
    {
        // 查找 Header Body 分隔的位置
        $crlf_pos = strpos($buffer, "\r\n\r\n");
        if ($crlf_pos === false) {
            return 0;
        }

        // 头长度
        $length = $crlf_pos + 4;
        // HTTP 协议第一行，包含 GET 路径和参数以及 HTTP 协议版本及方法
        [$http_version, $code, $msg] = \explode(' ', \strstr($buffer, "\r\n", true), 3);
        // 协议必须为 HTTP
        if (strstr($http_version, '/', true) !== 'HTTP') {
            $connection->close();
            return 0;
        }
        $http_version = explode('/', $http_version)[1];

        // 解析头
        $header = substr($buffer, 0, $crlf_pos);
        if ($pos = \strpos($header, "\r\nContent-Length: ")) {
            $length = $length + (int) \substr($header, $pos + 18, 10);
            $has_content_length = true;
        } elseif (\preg_match("/\r\ncontent-length: ?(\\d+)/i", $header, $match)) {
            $length = $length + intval($match[1]);
            $has_content_length = true;
        } else {
            $has_content_length = false;
            if (stripos($header, "\r\nTransfer-Encoding:") !== false) {
                $connection->close();
                return 0;
            }
        }
        if ($has_content_length) {
            return $length;
        }

        return 0;
    }

    private function executeResponse(SocketBase $base, string $package, Tcp $connection): bool
    {
        // TODO: 解析
        return false;
    }
}
