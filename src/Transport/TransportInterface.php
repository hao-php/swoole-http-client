<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient\Transport;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** 单次请求传输接口，不负责编码、重定向或业务策略。 */
interface TransportInterface
{
    /**
     * 完整发送一次请求并接收响应。
     * @param RequestInterface $request 不可变 HTTP 请求
     * @param array $options 已校验的请求选项
     */
    public function send(RequestInterface $request, array $options): ResponseInterface;
}
