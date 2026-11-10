<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient\Exception;

use Psr\Http\Client\RequestExceptionInterface;

/** 请求无法被当前 HTTP/1.1 传输层发送。 */
final class InvalidRequestException extends RequestException implements RequestExceptionInterface
{
}
