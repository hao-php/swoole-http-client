<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as SwooleClient;

/** 无连接池、无自动重试的协程 HTTP 客户端，实例可跨协程复用。 */
final class Client
{
    /**
     * 设置默认请求选项，实例自身不存储请求过程状态。
     * @param array $defaults 默认选项，单次请求可覆盖
     */
    public function __construct(private readonly array $defaults = [])
    {
    }

    /**
     * 发送请求；4xx/5xx 正常返回，网络失败抛出 RequestException。
     * @param string $method HTTP 方法
     * @param string $url HTTP 或 HTTPS 地址
     * @param array $options 请求头、参数、超时、重定向、TLS 和回调选项
     */
    public function request(string $method, string $url, array $options = []): Response
    {
        if (Coroutine::getCid() < 0) {
            throw new \LogicException('HTTP 请求必须在 Swoole 协程内执行');
        }
        $headers = self::headers($this->defaults['headers'] ?? []);
        $headers = array_replace($headers, self::headers($options['headers'] ?? []));
        $options = array_replace($this->defaults, $options);
        $method = strtoupper(trim($method));
        if (!preg_match('/^[!#$%&\x27*+.^_`|~0-9A-Z-]+$/D', $method)) {
            throw new \InvalidArgumentException('HTTP 方法无效');
        }
        $timeout = (float)($options['timeout'] ?? 5.0);
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('请求超时必须为大于零的有限秒数');
        }
        $redirect = $options['redirect'] ?? 3;
        if (!is_int($redirect) || $redirect < 0 || $redirect > 10) {
            throw new \InvalidArgumentException('重定向次数必须为 0 到 10 的整数');
        }
        foreach (['before', 'after'] as $key) {
            if (isset($options[$key]) && !is_callable($options[$key])) {
                throw new \InvalidArgumentException($key . ' 必须为可调用对象');
            }
        }
        $body = $options['body'] ?? [];
        $query = $options['query'] ?? [];
        if (!is_array($body) || !is_array($query)) {
            throw new \InvalidArgumentException('正文和查询参数必须为数组');
        }
        if (in_array($method, ['GET', 'HEAD'], true)) {
            $query = array_replace($body, $query);
            $body = [];
        }
        $url = Url::mergeQuery($url, $query);
        Url::parse($url);
        $encoded = null;
        if ($body !== [] || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $contentType = $headers['content-type'] ?? $options['content_type'] ?? 'application/json';
            if (!is_string($contentType) || preg_match('/[\r\n]/', $contentType)) {
                throw new \InvalidArgumentException('Content-Type 格式无效');
            }
            $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
            if ($mediaType === 'application/x-www-form-urlencoded') {
                $encoded = http_build_query($body, '', '&', PHP_QUERY_RFC3986);
            } elseif ($mediaType === 'application/json' || str_ends_with($mediaType, '+json')) {
                $encoded = json_encode($body, JSON_THROW_ON_ERROR);
            } else {
                throw new \InvalidArgumentException('数组正文仅支持 JSON 或 URL 编码表单');
            }
            $headers['content-type'] = $contentType;
        }
        $deadline = hrtime(true) / 1e9 + $timeout;
        for ($hop = 0; ; ++$hop) {
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                throw new RequestException('HTTP 请求超过总超时时间', 0, -2);
            }
            // 回调发生在每一跳实际发送前，接收到响应并关闭连接后执行 after。
            if (isset($options['before'])) {
                ($options['before'])($method, $url, $headers, $body);
            }
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                throw new RequestException('HTTP 请求超过总超时时间', 0, -2);
            }
            $response = $this->send($method, $url, $headers, $encoded, $remaining, $options);
            if (hrtime(true) / 1e9 > $deadline) {
                throw new RequestException('HTTP 请求超过总超时时间', 0, -2);
            }
            if (isset($options['after'])) {
                ($options['after'])($response);
            }
            $location = $response->headers['location'] ?? null;
            if ($redirect === 0 || !in_array($response->statusCode, [301, 302, 303, 307, 308], true)
                || !is_string($location) || $location === '') {
                return $response;
            }
            if ($hop >= $redirect) {
                throw new RequestException('HTTP 重定向次数超过上限');
            }
            $next = Url::resolve($url, $location);
            if (Url::origin($url) !== Url::origin($next)) {
                // 跨源跳转仅保留协议通用头，避免任意自定义凭据被带到新主机。
                $headers = array_intersect_key($headers, array_flip([
                    'accept', 'accept-encoding', 'accept-language', 'user-agent', 'content-type',
                ]));
            }
            unset($headers['host']);
            if (($response->statusCode === 303 && $method !== 'HEAD')
                || (in_array($response->statusCode, [301, 302], true) && $method === 'POST')) {
                $method = 'GET';
                $encoded = null;
                $body = [];
                unset($headers['content-type'], $headers['content-length'], $headers['transfer-encoding']);
            }
            $url = $next;
        }
    }

    /**
     * 执行单次请求，无论成功失败均关闭连接。
     * @param string $method HTTP 方法
     * @param string $url 请求地址
     * @param array $headers 已校验的请求头
     * @param string|null $body 已编码的正文
     * @param float $timeout 剩余超时秒数
     * @param array $options TLS 等请求选项
     */
    private function send(string $method, string $url, array $headers, ?string $body, float $timeout, array $options): Response
    {
        $parts = Url::parse($url);
        $host = trim($parts['host'], '[]');
        $ssl = $parts['scheme'] === 'https';
        $client = new SwooleClient($host, $parts['port'], $ssl);
        try {
            $settings = ['timeout' => $timeout, 'keep_alive' => false];
            if ($ssl) {
                $settings += [
                    'ssl_verify_peer' => true,
                    'ssl_allow_self_signed' => false,
                    'ssl_host_name' => $host,
                ];
                if (isset($options['ssl_cafile'])) {
                    $settings['ssl_cafile'] = $options['ssl_cafile'];
                }
            }
            $client->set($settings);
            $defaultPort = $ssl ? 443 : 80;
            $headers['host'] ??= $parts['host'] . ($parts['port'] === $defaultPort ? '' : ':' . $parts['port']);
            // 编码后重新计算长度，避免外部传入的长度与实际正文不一致。
            unset($headers['content-length'], $headers['transfer-encoding']);
            $client->setHeaders($headers);
            $client->setMethod($method);
            if ($body !== null) {
                $client->setData($body);
            }
            $target = ($parts['path'] ?? '') ?: '/';
            if (isset($parts['query'])) {
                $target .= '?' . $parts['query'];
            }
            if (!$client->execute($target) || $client->statusCode <= 0) {
                throw new RequestException('HTTP 连接或响应接收失败', $client->errCode, $client->statusCode);
            }
            $responseHeaders = array_change_key_case($client->headers ?: [], CASE_LOWER);
            if ($client->set_cookie_headers) {
                $responseHeaders['set-cookie'] = $client->set_cookie_headers;
            }
            return new Response($client->statusCode, $responseHeaders, $client->body, $url);
        } finally {
            $client->close();
        }
    }

    /**
     * 校验请求头并统一名称大小写。
     * @param array $headers 请求头键值数组
     */
    private static function headers(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !preg_match('/^[!#$%&\x27*+.^_`|~0-9A-Za-z-]+$/D', $name)
                || (!is_string($value) && !is_numeric($value))
                || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', (string)$value)) {
                throw new \InvalidArgumentException('HTTP 请求头格式无效');
            }
            $result[strtolower($name)] = (string)$value;
        }
        return $result;
    }

    /**
     * 发送 GET 请求。
     * @param string $url 请求地址
     * @param array $query 查询参数
     * @param array $options 其他请求选项
     */
    public function get(string $url, array $query = [], array $options = []): Response
    {
        $options['query'] = array_replace($options['query'] ?? [], $query);
        return $this->request('GET', $url, $options);
    }

    /**
     * 发送 POST 请求，默认使用 JSON 正文。
     * @param string $url 请求地址
     * @param array $body 正文数组
     * @param array $options 其他请求选项
     */
    public function post(string $url, array $body = [], array $options = []): Response
    {
        $options['body'] = $body;
        return $this->request('POST', $url, $options);
    }

    /**
     * 发送 JSON POST 请求，请求头中的 Content-Type 优先。
     * @param string $url 请求地址
     * @param array $body JSON 正文数组
     * @param array $options 其他请求选项
     */
    public function postJson(string $url, array $body = [], array $options = []): Response
    {
        $options['content_type'] ??= 'application/json';
        return $this->post($url, $body, $options);
    }

    /**
     * 发送 URL 编码表单 POST 请求，请求头中的 Content-Type 优先。
     * @param string $url 请求地址
     * @param array $body 表单正文数组
     * @param array $options 其他请求选项
     */
    public function postForm(string $url, array $body = [], array $options = []): Response
    {
        $options['content_type'] ??= 'application/x-www-form-urlencoded';
        return $this->post($url, $body, $options);
    }

    /**
     * 发送 PUT 请求。
     * @param string $url 请求地址
     * @param array $body 正文数组
     * @param array $options 其他请求选项
     */
    public function put(string $url, array $body = [], array $options = []): Response
    {
        $options['body'] = $body;
        return $this->request('PUT', $url, $options);
    }

    /**
     * 发送 PATCH 请求。
     * @param string $url 请求地址
     * @param array $body 正文数组
     * @param array $options 其他请求选项
     */
    public function patch(string $url, array $body = [], array $options = []): Response
    {
        $options['body'] = $body;
        return $this->request('PATCH', $url, $options);
    }

    /**
     * 发送 DELETE 请求。
     * @param string $url 请求地址
     * @param array $body 正文数组
     * @param array $options 其他请求选项
     */
    public function delete(string $url, array $body = [], array $options = []): Response
    {
        $options['body'] = $body;
        return $this->request('DELETE', $url, $options);
    }

    /**
     * 发送 HEAD 请求。
     * @param string $url 请求地址
     * @param array $query 查询参数
     * @param array $options 其他请求选项
     */
    public function head(string $url, array $query = [], array $options = []): Response
    {
        $options['query'] = array_replace($options['query'] ?? [], $query);
        return $this->request('HEAD', $url, $options);
    }

    /**
     * 发送 OPTIONS 请求。
     * @param string $url 请求地址
     * @param array $options 请求选项
     */
    public function options(string $url, array $options = []): Response
    {
        return $this->request('OPTIONS', $url, $options);
    }
}
