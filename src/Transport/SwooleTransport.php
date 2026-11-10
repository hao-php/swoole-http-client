<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient\Transport;

use GuzzleHttp\Psr7\Response;
use Haoa\SwooleHttpClient\Exception\InvalidRequestException;
use Haoa\SwooleHttpClient\Exception\NetworkException;
use Haoa\SwooleHttpClient\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as SwooleClient;

/** 无连接池的 HTTP/1.1 传输，每次发送独占并关闭一个原生客户端。 */
final class SwooleTransport implements TransportInterface
{
    /**
     * 将 PSR-7 请求转换为 Swoole 请求并接收响应。
     * @param RequestInterface $request 标准 HTTP 请求
     * @param array $options 超时、证书校验及内部截止时间
     */
    public function send(RequestInterface $request, array $options): ResponseInterface
    {
        if (Coroutine::getCid() < 0) {
            throw new RequestException('HTTP 请求必须在 Swoole 协程内执行', $request);
        }
        $uri = $request->getUri();
        if (!in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getHost() === ''
            || $uri->getUserInfo() !== '' || preg_match('/[\x00-\x20\x7f\\\\]/', (string)$uri)
            || !preg_match('/^[!#$%&\x27*+.^_`|~0-9A-Za-z-]+$/D', $request->getMethod())
            || $request->getProtocolVersion() !== '1.1') {
            throw new InvalidRequestException('只支持无账号密码的 HTTP/HTTPS 地址和 HTTP/1.1 请求', $request);
        }
        $target = $request->getRequestTarget();
        if (!str_starts_with($target, '/') && $target !== '*') {
            throw new InvalidRequestException('只支持源站路径或星号请求目标', $request);
        }
        if (preg_match('/[\x00-\x20\x7f]/', $target)) {
            throw new InvalidRequestException('HTTP 请求目标包含非法字符', $request);
        }
        $stream = $request->getBody();
        try {
            $position = $stream->isSeekable() ? $stream->tell() : null;
            try {
                $body = $stream->getContents();
            } finally {
                if ($position !== null) {
                    $stream->seek($position);
                }
            }
        } catch (\Throwable $exception) {
            throw new InvalidRequestException('请求正文不可读', $request, null, 0, $exception);
        }
        $deadline = $options['_deadline'] ?? hrtime(true) / 1e9 + $options['timeout'];
        $remaining = $deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            throw new NetworkException('HTTP 请求超时', $request, 0, -2);
        }
        $ssl = strtolower($uri->getScheme()) === 'https';
        $host = trim($uri->getHost(), '[]');
        $client = new SwooleClient($host, $uri->getPort() ?? ($ssl ? 443 : 80), $ssl);
        try {
            $settings = [
                'timeout' => $remaining,
                'connect_timeout' => min($options['connect_timeout'], $remaining),
                'keep_alive' => false,
                // 保留原始压缩正文，避免响应头与解压后的正文不一致。
                'http_compression' => false,
                'body_decompression' => false,
            ];
            if ($ssl) {
                $settings += [
                    'ssl_verify_peer' => $options['verify'] !== false,
                    'ssl_allow_self_signed' => $options['verify'] === false,
                    'ssl_host_name' => $host,
                ];
                if (is_string($options['verify'])) {
                    $settings['ssl_cafile'] = $options['verify'];
                }
            }
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $name = strtolower($name);
                if (in_array($name, ['content-length', 'transfer-encoding', 'connection', 'expect'], true)) {
                    continue;
                }
                // Cookie 使用分号连接；其余头遵循 PSR-7 的逗号连接方式。
                $headers[$name] = implode($name === 'cookie' ? '; ' : ', ', $values);
            }
            if (!isset($headers['host'])) {
                $headers['host'] = $uri->getHost() . ($uri->getPort() === null ? '' : ':' . $uri->getPort());
            }
            $headers['connection'] = 'close';
            $client->set($settings);
            $client->setHeaders($headers);
            $client->setMethod($request->getMethod());
            if ($body !== '' || $request->hasHeader('Content-Length')) {
                $client->setData($body);
            }
            // 延迟接收，使连接/发送后接收阶段使用剩余总预算。
            $client->setDefer(true);
            if (!$client->execute($target)) {
                throw new NetworkException('HTTP 连接或发送失败', $request, $client->errCode, $client->statusCode);
            }
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                throw new NetworkException('HTTP 请求超时', $request, 0, -2);
            }
            if (!$client->recv($remaining) || $client->statusCode < 200) {
                throw new NetworkException('HTTP 响应接收失败', $request, $client->errCode, $client->statusCode);
            }
            $responseHeaders = $client->headers ?: [];
            if ($client->set_cookie_headers) {
                $responseHeaders['set-cookie'] = $client->set_cookie_headers;
            }
            try {
                return new Response($client->statusCode, $responseHeaders, $client->body);
            } catch (\InvalidArgumentException $exception) {
                throw new RequestException('HTTP 响应格式无效', $request, null, 0, $exception);
            }
        } finally {
            $client->close();
        }
    }
}
