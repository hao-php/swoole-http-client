# Swoole HTTP Client

面向通用 PHP 库与应用的协程 HTTP 客户端，采用 PSR-7 消息和可替换的同步传输层；参考 PSR-18 的请求与异常设计，目前不声明完整 PSR-18 兼容。

要求 PHP 8.1+、Swoole 5.0+；HTTPS 需要 OpenSSL。消息与 URI 处理复用 `guzzlehttp/psr7`，不依赖 Guzzle HTTP 客户端、Promise 或其他框架。目前仅支持缓冲式 HTTP/1.1，不使用连接池，不自动重试，每次单独创建并关闭连接。

## 安装

尚未发布 tag 或注册 Packagist，可通过 VCS 引用 main 分支：

```json
{
  "repositories": [
    {"type": "vcs", "url": "https://github.com/hao-php/swoole-http-client.git"}
  ],
  "require": {"haoa/swoole-http-client": "dev-main"}
}
```

本地开发可将 VCS 替换成 `{"type":"path","url":"../hao-php/swoole-http-client"}`，路径相对于使用方项目；容器内也必须能访问该路径。

## 便捷请求

```php
use Haoa\SwooleHttpClient\Client;

Swoole\Coroutine\run(function (): void {
    $client = new Client(['base_uri' => 'https://example.com/api/', 'timeout' => 5.0]);

    $response = $client->get('items?page=1', ['query' => ['page' => 2]]);
    $response = $client->post('items', ['json' => ['name' => '示例']]);
    $response = $client->post('login', ['form_params' => ['username' => '示例']]);
    $response = $client->put('items/1', [
        'headers' => ['Content-Type' => 'text/plain; charset=utf-8'],
        'body' => '原始字符串正文',
    ]);

    echo $response->getStatusCode();
    echo (string) $response->getBody();
    $cookies = $response->getHeader('Set-Cookie');
});
```

已处于协程中的服务直接调用，无需嵌套 `Coroutine\run()`。`get/post/put/patch/delete/head/options` 的第二个参数统一为选项数组，其他方法可用 `request($method, $url, $options)`。

库返回标准 `Psr\Http\Message\ResponseInterface`，正文是 PSR-7 Stream，不附加业务 Result、JSON 解码规则或日志脱敏。JSON 响应由使用方按需解析。

| 选项 | 默认值 | 说明 |
| --- | --- | --- |
| `base_uri` | 无 | 按 RFC 3986 解析相对地址，注意基地址末尾斜线的含义 |
| `headers` | `[]` | 标准 PSR-7 请求头，值可为字符串或多值数组；单次请求覆盖默认同名头 |
| `query` | 无 | 数组与现有查询按键合并；字符串作为完整查询，保留重复键与原始编码 |
| `body` | 无 | 原始字符串、资源或 PSR-7 Stream；数组不允许传入此选项 |
| `json` | 无 | 任意可 JSON 编码的值，包括数组、对象、标量与 null |
| `form_params` | 无 | URL 编码表单数组 |
| `timeout` | `5.0` | 大于零的秒数，重定向链共享预算，接收阶段使用连接发送后的剩余时间 |
| `connect_timeout` | `5.0` | 大于零的连接超时秒数，不超过剩余请求预算 |
| `allow_redirects` | `3` | 最多跟随次数；false/0 禁用，true 等于 3 |
| `verify` | `true` | 校验证书与主机名，字符串表示自定义 CA 文件；false 仅用于受控调试 |

`body/json/form_params` 互斥，单次正文配置替换默认正文配置。编码由选项决定，Content-Type 只在未提供时补充。GET/HEAD 不会把 body 自动转为 query，空 POST 不会自动发送 `[]`。

连接层会计算实际 Content-Length、移除 Transfer-Encoding、关闭 Keep-Alive，并由目标 URI 决定 TLS 主机校验。当前不实现 Expect 握手，会移除 Expect 头。响应保留原始压缩正文及对应响应头，不自动解压。

## PSR-7 请求入口

```php
use GuzzleHttp\Psr7\Request;
use Haoa\SwooleHttpClient\Client;

// 在 Swoole 协程内执行。
$client = new Client();
$request = new Request('GET', 'https://example.com/items');
$response = $client->sendRequest($request);
```

`sendRequest()` 接收完整的 PSR-7 请求，不合并默认请求头、base_uri 或正文，也不跟随重定向。默认配置中仅传输设置对它生效。4xx/5xx 与重定向都是正常响应。

异常实现 PSR-18 接口：

- `Exception\RequestException`：客户端异常基类，实现 `ClientExceptionInterface`，可读取关联请求和响应。
- `Exception\InvalidRequestException`：不可发送的请求，实现 `RequestExceptionInterface`。
- `Exception\NetworkException`：网络与超时错误，实现 `NetworkExceptionInterface`；`getCode()` 为 Swoole 错误码，`transportStatus` 为 Swoole 传输状态。

便捷选项错误抛出 `InvalidArgumentException`，JSON 编码错误抛出 `JsonException`。中间件自己的异常不被转换或吞掉。异常关联的请求可能包含凭据，记录日志时需由使用方脱敏。

## 架构与扩展

```text
Client（PSR-7 请求与便捷入口）
  ├─ RequestOptions：默认配置和选项校验
  ├─ RequestFactory：便捷选项 → PSR-7 请求
  └─ RedirectMiddleware → 自定义中间件 → TransportInterface
                                          └─ SwooleTransport
```

参考 Guzzle 的消息、策略和传输分离方式，但处理链使用同步协程调用，不引入 Promise。Client、消息构建器和重定向策略都不依赖 Swoole；只有 SwooleTransport 调用原生客户端。

自定义中间件按传入顺序进入、逆序返回，可处理日志、鉴权、追踪和请求计时：

```php
use Haoa\SwooleHttpClient\Client;
use Haoa\SwooleHttpClient\Middleware\MiddlewareInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

$middleware = new class implements MiddlewareInterface {
    /**
     * 为请求追加追踪头。
     * @param RequestInterface $request 原始请求
     * @param array $options 请求选项
     * @param callable $next 下游处理器
     */
    public function process(RequestInterface $request, array $options, callable $next): ResponseInterface
    {
        return $next($request->withHeader('X-Trace-Id', bin2hex(random_bytes(8))), $options);
    }
};
$client = new Client(middleware: [$middleware]);
```

重定向处于最外层，因此中间件会在每一跳执行。中间件可以改变请求、响应或捕获异常；不要在中间件中无条件重新注入被跨源重定向清除的凭据。`_deadline` 是内部选项，扩展代码应保留它。

可通过 `new Client(transport: $transport)` 注入 `TransportInterface` 实现，在不联网的环境中返回固定响应；后续连接池也可在此边界扩展。库不会自动添加重试、缓存、Cookie 会话或业务策略。默认客户端没有请求过程的实例状态，可跨协程复用；自定义中间件、默认正文流和外部资源的协程安全由调用方负责。

## 重定向与能力边界

只跟随 301、302、303、307、308。301/302 的 POST 和 303 的非 HEAD 请求改为 GET；307/308 保留方法和正文。跨源跳转只保留 Host、Accept、Accept-Encoding、Accept-Language、User-Agent 及正文类型/长度头，其他自定义头均清除。拒绝 HTTPS 降级到 HTTP。超限或无法重放正文时抛出异常。

资源与 Stream 正文会完整缓冲到内存；可定位流读取后恢复原位置，不可定位流会被消耗，不能依赖它进行重定向重放。响应同样完整缓冲。暂不支持大文件流式传输、multipart、代理、HTTP/2、WebSocket、自动 Cookie 会话及自动重试。

当前 Swoole 6.2.3 原生 HTTP 客户端在本地“103 Early Hints 后接 200 响应”检查中接收失败，库会报告异常，不能保证 PSR-18 要求的 1xx 临时响应组装。因此 Client 不实现 `Psr\\Http\\Client\\ClientInterface`，不要将它作为完整 PSR-18 客户端交给依赖方。原生客户端未暴露的响应元数据和重复头信息也不保证完整保留，Set-Cookie 单独处理。

## 首版接口调整

当前尚未发布稳定 tag，本次将项目专用约定移出公共库：

- `get($url, $query)` 改为 `get($url, ['query' => $query])`。
- `postJson/postForm` 改为 `post($url, ['json' => $data])` / `post($url, ['form_params' => $data])`。
- `redirect/ssl_cafile` 改为 `allow_redirects/verify`。
- `before/after` 回调改用中间件；自定义 Response、Url 和根命名空间 RequestException 改为 PSR-7 消息、UriResolver 和 Exception 命名空间。
- 项目若希望统一接收数组、自动选择正文编码或转换 Result，应在项目自己的适配层实现。

设计参考：[PSR-7](https://www.php-fig.org/psr/psr-7/)、[PSR-18](https://www.php-fig.org/psr/psr-18/)、[Guzzle Handler/Middleware](https://docs.guzzlephp.org/en/stable/handlers-and-middleware.html)。
