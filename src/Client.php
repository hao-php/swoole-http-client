<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient;

use Haoa\SwooleHttpClient\Middleware\MiddlewareInterface;
use Haoa\SwooleHttpClient\Middleware\RedirectMiddleware;
use Haoa\SwooleHttpClient\Transport\SwooleTransport;
use Haoa\SwooleHttpClient\Transport\TransportInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** 标准 HTTP 客户端入口，只负责选项、消息构建和处理链编排。 */
final class Client
{
    private readonly \Closure $handler;

    /**
     * 组装不可变配置和同步中间件链，实例可跨协程复用。
     * @param array $defaults 默认请求选项
     * @param TransportInterface|null $transport 单次请求传输器，默认使用 Swoole
     * @param MiddlewareInterface[] $middleware 自定义中间件，按数组顺序进入、逆序返回
     */
    public function __construct(
        private readonly array $defaults = [],
        ?TransportInterface $transport = null,
        array $middleware = [],
    ) {
        RequestOptions::merge($defaults, []);
        $transport ??= new SwooleTransport();
        $handler = $transport->send(...);
        // 重定向位于最外层，自定义中间件会观察到每一次实际发送。
        array_unshift($middleware, new RedirectMiddleware());
        foreach (array_reverse($middleware) as $item) {
            if (!$item instanceof MiddlewareInterface) {
                throw new \InvalidArgumentException('中间件必须实现 MiddlewareInterface');
            }
            $next = $handler;
            $handler = static fn (RequestInterface $request, array $options): ResponseInterface => $item->process($request, $options, $next);
        }
        $this->handler = $handler;
    }

    /**
     * 发送 PSR-7 请求；不自动跟随重定向，暂不声明完整 PSR-18 兼容。
     * @param RequestInterface $request 调用方构造的完整请求
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $options = RequestOptions::merge($this->defaults, ['allow_redirects' => false]);
        $options['_deadline'] = hrtime(true) / 1e9 + $options['timeout'];
        return ($this->handler)($request, $options);
    }

    /**
     * 根据便捷选项构建并发送请求，4xx/5xx 正常返回响应。
     * @param string $method HTTP 方法
     * @param string $url 绝对地址或相对 base_uri 的引用
     * @param array $options 本次请求选项
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options = RequestOptions::merge($this->defaults, $options);
        $request = RequestFactory::create($method, $url, $options);
        $options['_deadline'] = hrtime(true) / 1e9 + $options['timeout'];
        return ($this->handler)($request, $options);
    }

    /**
     * 发送 GET 请求。
     * @param string $url 请求地址
     * @param array $options 请求选项
     */
    public function get(string $url, array $options = []): ResponseInterface
    {
        return $this->request('GET', $url, $options);
    }

    /**
     * 发送 POST 请求，不默认附加正文或 Content-Type。
     * @param string $url 请求地址
     * @param array $options 请求选项
     */
    public function post(string $url, array $options = []): ResponseInterface
    {
        return $this->request('POST', $url, $options);
    }

    /**
     * 发送 PUT 请求。
     * @param string $url 请求地址
     * @param array $options 请求选项
     */
    public function put(string $url, array $options = []): ResponseInterface
    {
        return $this->request('PUT', $url, $options);
    }

    /**
     * 发送 PATCH 请求。
     * @param string $url 请求地址
     * @param array $options 请求选项
     */
    public function patch(string $url, array $options = []): ResponseInterface
    {
        return $this->request('PATCH', $url, $options);
    }

    /**
     * 发送 DELETE 请求。
     * @param string $url 请求地址
     * @param array $options 请求选项
     */
    public function delete(string $url, array $options = []): ResponseInterface
    {
        return $this->request('DELETE', $url, $options);
    }

    /**
     * 发送 HEAD 请求。
     * @param string $url 请求地址
     * @param array $options 请求选项
     */
    public function head(string $url, array $options = []): ResponseInterface
    {
        return $this->request('HEAD', $url, $options);
    }

    /**
     * 发送 OPTIONS 请求。
     * @param string $url 请求地址
     * @param array $options 请求选项
     */
    public function options(string $url, array $options = []): ResponseInterface
    {
        return $this->request('OPTIONS', $url, $options);
    }
}
