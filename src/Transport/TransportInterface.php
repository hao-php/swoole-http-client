<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient\Transport;

use Haoa\SwooleHttpClient\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** 单次请求传输接口，不负责编码、重定向或业务策略。 */
interface TransportInterface
{
    /**
     * 完整发送一次请求并接收响应。
     * @param RequestInterface $request 不可变 HTTP 请求
     * @param RequestOptions $options 已校验的请求选项
     * @param float|null $deadline 内部单调时钟截止时间，直接使用传输器时可省略
     */
    public function send(RequestInterface $request, RequestOptions $options, ?float $deadline = null): ResponseInterface;
}
