<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient;

/** 网络连接或响应接收失败；HTTP 错误状态不属于此异常。 */
final class RequestException extends \RuntimeException
{
    /**
     * 创建请求异常，异常消息不包含 URL 查询参数。
     * @param string $message 错误说明
     * @param int $errorCode Swoole 网络错误码
     * @param int $transportStatus Swoole 传输状态码
     */
    public function __construct(string $message, int $errorCode = 0, public readonly int $transportStatus = 0)
    {
        parent::__construct($message, $errorCode);
    }
}
