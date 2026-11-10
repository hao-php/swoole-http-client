<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient;

/** 已完整接收的 HTTP 响应，不持有网络连接。 */
final class Response
{
    /**
     * 创建响应快照。
     * @param int $statusCode HTTP 状态码
     * @param array $headers 响应头，名称统一为小写，重复值使用数组
     * @param string $body 原始响应正文
     * @param string $url 最终请求地址
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $url,
    ) {
    }

    /** 获取 HTTP 状态码。 */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** 获取全部响应头。 */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** 获取原始响应正文。 */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * 解析 JSON，格式错误时抛出 JsonException。
     * @param bool $associative 是否将对象解析为关联数组
     */
    public function json(bool $associative = true): mixed
    {
        return json_decode($this->body, $associative, 512, JSON_THROW_ON_ERROR);
    }
}
