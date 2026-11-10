<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient;

use Haoa\SwooleHttpClient\Internal\UnsetValue;
use Psr\Http\Message\StreamInterface;

/** 不可变请求配置，未指定的配置继承客户端默认值。 */
final class RequestOptions
{
    /**
     * 设置请求配置；body、json 和 formParams 互斥。
     * @param string|null $baseUri 相对地址的基地址，null 表示继承
     * @param array $headers 请求头，同名头覆盖默认值，名称大小写无关
     * @param array|string|null $query 查询数组或完整查询字符串，null 表示继承
     * @param mixed $body 原始字符串、资源或 PSR-7 流，未设置时继承默认正文
     * @param mixed $json 可 JSON 编码的值，显式 null 会发送 JSON null
     * @param array|UnsetValue $formParams URL 编码表单数组，未设置时继承默认正文
     * @param float|null $timeout 请求总预算秒数，null 表示继承，最终默认为 5 秒
     * @param float|null $connectTimeout 连接超时秒数，null 表示继承，最终默认为 5 秒
     * @param int|bool|null $allowRedirects 重定向次数，false 为禁用，true 为 3 次，null 表示继承
     * @param bool|string|null $verify 是否校验证书或自定义 CA 路径，null 表示继承，最终默认为 true
     * @param array $attributes 中间件扩展数据，不影响传输，按键覆盖默认数据
     */
    public function __construct(
        /** 相对 URI 的基地址；末尾斜线遵循 RFC 3986，null 表示继承。 */
        public readonly ?string $baseUri = null,
        /** 请求头，值为字符串或字符串数组；同名头大小写无关。 */
        public readonly array $headers = [],
        /** 查询数组按键合并 URL 参数；字符串替换完整查询，null 表示继承。 */
        public readonly array|string|null $query = null,
        /** 原始字符串、资源或 PSR-7 流；与 json、formParams 互斥。 */
        public readonly mixed $body = UnsetValue::Value,
        /** 任意可 JSON 编码的值；显式 null 与未设置不同。 */
        public readonly mixed $json = UnsetValue::Value,
        /** URL 编码表单数组；空数组表示发送空表单。 */
        public readonly array|UnsetValue $formParams = UnsetValue::Value,
        /** 请求总预算秒数，必须为有限正数；继承后默认 5 秒。 */
        public readonly ?float $timeout = null,
        /** 建立连接超时秒数，必须为有限正数；继承后默认 5 秒。 */
        public readonly ?float $connectTimeout = null,
        /** 最多重定向次数；false/0 禁用，true 为 3 次，继承后默认 3 次。 */
        public readonly int|bool|null $allowRedirects = null,
        /** true 校验证书和主机名，字符串指定 CA 文件，false 仅用于受控调试。 */
        public readonly bool|string|null $verify = null,
        /** 中间件自定义数据，如项目结构化日志参数；不参与 HTTP 编码。 */
        public readonly array $attributes = [],
    ) {
        foreach (['timeout' => $timeout, 'connectTimeout' => $connectTimeout] as $name => $value) {
            if ($value !== null && (!is_finite($value) || $value <= 0)) {
                throw new \InvalidArgumentException($name . ' 必须为大于零的有限秒数');
            }
        }
        if (is_int($allowRedirects) && $allowRedirects < 0) {
            throw new \InvalidArgumentException('allowRedirects 必须为非负整数或布尔值');
        }
        if (is_string($verify) && (!is_file($verify) || !is_readable($verify))) {
            throw new \InvalidArgumentException('CA 文件不存在或不可读');
        }
        $bodyCount = (int)($body !== UnsetValue::Value) + (int)($json !== UnsetValue::Value)
            + (int)($formParams !== UnsetValue::Value);
        if ($bodyCount > 1) {
            throw new \InvalidArgumentException('body、json、formParams 不能同时设置');
        }
        if ($body !== UnsetValue::Value && !is_string($body) && !is_resource($body) && !$body instanceof StreamInterface) {
            throw new \InvalidArgumentException('body 必须为字符串、资源或 PSR-7 流');
        }
    }

    /**
     * 继承客户端配置；无默认配置时直接复用当前对象。
     * @param self|null $defaults 客户端默认配置
     */
    public function withDefaults(?self $defaults): self
    {
        if ($defaults === null) {
            return $this;
        }
        $headers = [];
        foreach ([$defaults->headers, $this->headers] as $values) {
            foreach ($values as $name => $value) {
                $headers[strtolower((string)$name)] = [(string)$name, $value];
            }
        }
        $mergedHeaders = [];
        foreach ($headers as [$name, $value]) {
            $mergedHeaders[$name] = $value;
        }
        $hasBody = $this->body !== UnsetValue::Value || $this->json !== UnsetValue::Value
            || $this->formParams !== UnsetValue::Value;
        return new self(
            baseUri: $this->baseUri ?? $defaults->baseUri,
            headers: $mergedHeaders,
            query: $this->query ?? $defaults->query,
            body: $hasBody ? $this->body : $defaults->body,
            json: $hasBody ? $this->json : $defaults->json,
            formParams: $hasBody ? $this->formParams : $defaults->formParams,
            timeout: $this->timeout ?? $defaults->timeout,
            connectTimeout: $this->connectTimeout ?? $defaults->connectTimeout,
            allowRedirects: $this->allowRedirects ?? $defaults->allowRedirects,
            verify: $this->verify ?? $defaults->verify,
            attributes: array_replace($defaults->attributes, $this->attributes),
        );
    }

    /** 获取继承后请求总预算秒数。 */
    public function getTimeout(): float
    {
        return $this->timeout ?? 5.0;
    }

    /** 获取继承后连接超时秒数。 */
    public function getConnectTimeout(): float
    {
        return $this->connectTimeout ?? 5.0;
    }

    /** 获取继承后允许的重定向次数。 */
    public function getRedirectLimit(): int
    {
        return match ($this->allowRedirects) {
            null, true => 3,
            false => 0,
            default => $this->allowRedirects,
        };
    }
}
