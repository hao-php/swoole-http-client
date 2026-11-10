<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/** 连接、发送、接收或超时失败。 */
final class NetworkException extends RequestException implements NetworkExceptionInterface
{
    /**
     * 保存 Swoole 网络错误状态。
     * @param string $message 网络错误说明
     * @param RequestInterface $request 异常请求
     * @param int $errorCode Swoole 错误码
     * @param int $transportStatus Swoole 传输状态
     */
    public function __construct(string $message, RequestInterface $request, int $errorCode = 0, public readonly int $transportStatus = 0)
    {
        parent::__construct($message, $request, null, $errorCode);
    }
}
