<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** 客户端异常基类，HTTP 4xx/5xx 不触发异常。 */
class RequestException extends \RuntimeException implements ClientExceptionInterface
{
    /**
     * 保存异常关联的请求和响应。
     * @param string $message 不包含凭据的错误说明
     * @param RequestInterface $request 异常关联的请求
     * @param ResponseInterface|null $response 已收到的响应
     * @param int $code 底层错误码
     * @param \Throwable|null $previous 原始异常
     */
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        private readonly ?ResponseInterface $response = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** 获取关联请求。 */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    /** 获取关联响应，未收到时为 null。 */
    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }
}
