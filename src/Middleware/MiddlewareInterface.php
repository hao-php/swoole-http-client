<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient\Middleware;

use Haoa\SwooleHttpClient\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** 同步协程中间件，按调用栈顺序包裹下游处理器。 */
interface MiddlewareInterface
{
    /**
     * 扩展请求或响应处理，网络挂起由 Swoole 调度。
     * @param RequestInterface $request HTTP 请求
     * @param RequestOptions $options 请求选项
     * @param callable(RequestInterface, RequestOptions): ResponseInterface $next 下游处理器
     */
    public function process(RequestInterface $request, RequestOptions $options, callable $next): ResponseInterface;
}
