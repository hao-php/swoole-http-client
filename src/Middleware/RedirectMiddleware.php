<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient\Middleware;

use Haoa\SwooleHttpClient\RequestOptions;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Haoa\SwooleHttpClient\Exception\InvalidRequestException;
use Haoa\SwooleHttpClient\Exception\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/** 重定向策略与传输层分离，复用成熟的 URI 相对引用解析。 */
final class RedirectMiddleware implements MiddlewareInterface
{
    /**
     * 跟随允许的重定向，所有跳转共享同一次请求的截止时间。
     * @param RequestInterface $request 初始请求
     * @param RequestOptions $options 请求选项
     * @param callable(RequestInterface, RequestOptions): ResponseInterface $next 单次下游处理器
     */
    public function process(RequestInterface $request, RequestOptions $options, callable $next): ResponseInterface
    {
        for ($hop = 0; ; ++$hop) {
            $response = $next($request, $options);
            $status = $response->getStatusCode();
            if ($options->getRedirectLimit() === 0 || !in_array($status, [301, 302, 303, 307, 308], true)
                || !$response->hasHeader('Location')) {
                return $response;
            }
            if ($hop >= $options->getRedirectLimit()) {
                throw new RequestException('HTTP 重定向次数超过上限', $request, $response);
            }
            $locations = $response->getHeader('Location');
            if (count($locations) !== 1) {
                throw new RequestException('HTTP 重定向地址不唯一', $request, $response);
            }
            try {
                $uri = UriResolver::resolve($request->getUri(), new Uri($locations[0]))->withFragment('');
            } catch (\InvalidArgumentException $exception) {
                throw new RequestException('HTTP 重定向地址无效', $request, $response, 0, $exception);
            }
            if (!in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getUserInfo() !== '') {
                throw new RequestException('HTTP 重定向协议或地址不受支持', $request, $response);
            }
            // 避免 HTTPS 正文通过降级跳转发送到明文连接。
            if ($request->getUri()->getScheme() === 'https' && $uri->getScheme() === 'http') {
                throw new RequestException('不允许 HTTPS 重定向到 HTTP', $request, $response);
            }
            $rewrite = ($status === 303 && strtoupper($request->getMethod()) !== 'HEAD')
                || (in_array($status, [301, 302], true) && strtoupper($request->getMethod()) === 'POST');
            $nextRequest = $request->withUri($uri);
            if ($rewrite) {
                $nextRequest = $nextRequest->withMethod('GET')->withBody(Utils::streamFor(''));
                foreach (['Content-Type', 'Content-Length', 'Transfer-Encoding', 'Expect'] as $name) {
                    $nextRequest = $nextRequest->withoutHeader($name);
                }
            } elseif (!$request->getBody()->isSeekable() && $request->getBody()->getSize() !== 0) {
                throw new InvalidRequestException('重定向无法重放不可定位的正文流', $request, $response);
            }
            if (self::origin($request->getUri()) !== self::origin($uri)) {
                foreach (array_keys($nextRequest->getHeaders()) as $name) {
                    if (!in_array(strtolower($name), ['host', 'accept', 'accept-encoding', 'accept-language', 'user-agent', 'content-type', 'content-length'], true)) {
                        $nextRequest = $nextRequest->withoutHeader($name);
                    }
                }
            }
            $request = $nextRequest;
        }
    }

    /**
     * 获取规范化源站标识。
     * @param UriInterface $uri 请求 URI
     */
    private static function origin(UriInterface $uri): string
    {
        return strtolower($uri->getScheme() . '://' . $uri->getHost()) . ':'
            . ($uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80));
    }
}
