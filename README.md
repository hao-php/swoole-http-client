# Swoole HTTP Client

面向通用 PHP 库与应用的协程 HTTP 客户端，采用 PSR-7 消息、不可变请求配置和可替换的同步传输层。参考 PSR-18 的请求与异常设计，目前不声明完整 PSR-18 兼容。

要求 PHP 8.1+、Swoole 5.0+；HTTPS 需要 OpenSSL。消息与 URI 处理复用 `guzzlehttp/psr7`，不依赖 Guzzle HTTP 客户端、Promise 或业务框架。目前仅支持缓冲式 HTTP/1.1，不使用连接池，不自动重试，每次单独创建并关闭连接。

## 安装

尚未发布稳定 tag，可通过 VCS 引用 main 分支：

```json
{
  "repositories": [
    {"type": "vcs", "url": "https://github.com/hao-php/swoole-http-client.git"}
  ],
  "require": {"haoa/swoole-http-client": "dev-main"}
}
```

本地开发可将 VCS 替换成 `{"type":"path","url":"../hao-php/swoole-http-client","options":{"symlink":true}}`，路径相对于使用方项目，容器内也必须能访问。

## 请求配置对象

`Client` 默认配置和每次请求配置都使用 `RequestOptions`，所有公开属性均有中文注释，可通过 IDE 补全。对象属性只读，不提供数组配置或对象转数组的处理路径。

```php
use Haoa\SwooleHttpClient\Client;
use Haoa\SwooleHttpClient\RequestOptions;

Swoole\Coroutine\run(function (): void {
    $client = new Client(new RequestOptions(
        baseUri: 'https://example.com/api/',
        timeout: 5.0,
        headers: ['Accept' => 'application/json'],
    ));

    $response = $client->get('items?page=1', new RequestOptions(query: ['page' => 2]));
    $response = $client->post('items', new RequestOptions(json: ['name' => '示例']));
    $response = $client->post('login', new RequestOptions(formParams: ['username' => '示例']));
    $response = $client->put('items/1', new RequestOptions(
        headers: ['Content-Type' => 'text/plain; charset=utf-8'],
        body: '原始字符串正文',
        allowRedirects: false,
    ));

    echo $response->getStatusCode();
    echo (string) $response->getBody();
    $cookies = $response->getHeader('Set-Cookie');
});
```

已处于协程中的服务直接调用，不要嵌套 `Coroutine\run()`。`get/post/put/patch/delete/head/options` 的第二个参数统一为可省略的 `RequestOptions`；其他方法使用 `request($method, $url, $options)`。

| 属性 | 类型 | 含义 |
| --- | --- | --- |
| `baseUri` | `?string` | 按 RFC 3986 解析相对地址，注意末尾斜线；null 表示继承 |
| `headers` | `array` | 头值为字符串或字符串数组，单次配置覆盖默认同名头，大小写无关 |
| `query` | `array|string|null` | 数组按键合并 URL 查询；字符串替换完整查询并保留重复键，null 表示继承 |
| `body` | `mixed` | 原始字符串、资源或 PSR-7 Stream，数组不可传入 |
| `json` | `mixed` | 任意可 JSON 编码的值，显式 null 表示发送 JSON null |
| `formParams` | `array` 或内部未设置标记 | URL 编码表单数组，空数组表示发送空表单 |
| `timeout` | `?float` | 大于零的有限秒数；null 表示继承，最终默认 5 秒 |
| `connectTimeout` | `?float` | 连接超时；null 表示继承，最终默认 5 秒，并受剩余预算限制 |
| `allowRedirects` | `int|bool|null` | 最多跟随次数，false/0 禁用，true 为 3 次；null 表示继承，最终默认 3 次 |
| `verify` | `bool|string|null` | true 校验证书与主机名，字符串指定 CA 文件，false 仅用于受控调试；null 表示继承，最终默认 true |
| `attributes` | `array` | 中间件扩展数据，不参与 HTTP 编码，按键覆盖默认数据 |

正文选项使用内部标记区分未设置和 null，调用方无需直接使用标记：

```php
new RequestOptions();                 // 未设置正文，可继承默认正文
new RequestOptions(json: null);       // 明确发送 JSON null，替换默认正文
new RequestOptions(json: []);         // 发送 JSON []
new RequestOptions(body: '');         // 明确使用空正文，替换默认正文
new RequestOptions(formParams: []);   // 发送空表单，替换默认正文
```

`body/json/formParams` 互斥。单次配置指定任何一种正文时，整体替换客户端默认正文格式。编码由选项决定，Content-Type 仅在未提供时补充。GET/HEAD 不会把 body 自动转为 query，空 POST 不会自动发送 `[]`。配置错误在创建对象时抛出 `InvalidArgumentException` 或类型错误，JSON 编码失败在构建请求时抛出 `JsonException`。

无本次配置时直接复用默认对象，无默认配置时直接使用传入对象，两者同时存在时生成合并对象，不修改原配置。`getTimeout()`、`getConnectTimeout()`、`getRedirectLimit()` 返回继承合并后的有效值。

## PSR-7 请求与响应

库返回 `Psr\Http\Message\ResponseInterface`，正文是 PSR-7 Stream，不附加业务 Result、JSON 解码或日志脱敏。

```php
use GuzzleHttp\Psr7\Request;
use Haoa\SwooleHttpClient\Client;

// 在 Swoole 协程内执行。
$client = new Client();
$response = $client->sendRequest(new Request('GET', 'https://example.com/items'));
```

`sendRequest()` 接收完整 PSR-7 请求，不合并默认请求头、baseUri 或正文，不跟随重定向；默认配置中的传输设置和 attributes 仍可使用。HTTP 4xx/5xx 与重定向都是正常响应。

异常采用 PSR-18 的异常接口：

- `Exception\RequestException`：客户端异常基类，实现 `ClientExceptionInterface`，可读取关联请求和响应。
- `Exception\InvalidRequestException`：不可发送的请求，实现 `RequestExceptionInterface`。
- `Exception\NetworkException`：网络和超时错误，实现 `NetworkExceptionInterface`；`getCode()` 为 Swoole 错误码，`transportStatus` 为 Swoole 状态。

中间件异常原样传播。异常关联的请求可能包含凭据，记录时需由使用方脱敏。

## 架构与中间件

```text
Client → RequestFactory → PSR-7 请求
  └─ RedirectMiddleware → 自定义中间件 → TransportInterface
                                          └─ SwooleTransport
```

Client 负责配置合并与编排；RequestFactory 负责编码；中间件负责请求策略；传输层只执行单次收发。配置对象贯穿处理链，不转回选项数组。超时截止时间由 Client 在单次调用中单独传递给传输层，不放进公开配置，不保存在共享实例中。

```php
use Haoa\SwooleHttpClient\Client;
use Haoa\SwooleHttpClient\RequestOptions;
use Haoa\SwooleHttpClient\Middleware\MiddlewareInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

$middleware = new class implements MiddlewareInterface {
    /**
     * 追加追踪头。
     * @param RequestInterface $request 原始请求
     * @param RequestOptions $options 不可变配置，可通过 attributes 读取项目扩展数据
     * @param callable $next 下游处理器
     */
    public function process(RequestInterface $request, RequestOptions $options, callable $next): ResponseInterface
    {
        return $next($request->withHeader('X-Trace-Id', bin2hex(random_bytes(8))), $options);
    }
};
$client = new Client(middleware: [$middleware]);
// 在协程内使用：
$response = $client->post('https://example.com/items', new RequestOptions(
    json: ['name' => '示例'],
    attributes: ['operation' => '创建示例'],
));
```

中间件按数组顺序进入、逆序返回；重定向在最外层，因此每一跳均经过自定义中间件。日志、鉴权和追踪由调用方实现；不要无条件重新注入跨源跳转清除的凭据。

通过 `new Client(transport: $transport)` 可注入 `TransportInterface` 实现，方法为 `send(RequestInterface $request, RequestOptions $options, ?float $deadline = null): ResponseInterface`。第三个参数是单调时钟截止时间，用于同一重定向链共享预算；自定义传输器应遵守它。模拟传输可用于无网络验证，后续连接池也可在此边界扩展。

Client 和内置中间件不保存请求过程状态，可跨协程复用。readonly 仅限制属性赋值：配置中嵌套的对象、正文流、资源和中间件捕获的外部状态仍由调用方保证协程安全。

## 重定向与传输边界

只跟随 301、302、303、307、308。301/302 的 POST 和 303 的非 HEAD 请求改为 GET；307/308 保留方法和正文。跨源跳转仅保留 Host、Accept、Accept-Encoding、Accept-Language、User-Agent 及正文类型/长度头。拒绝 HTTPS 降级到 HTTP。超限或无法重放正文时抛异常。

连接层计算实际 Content-Length，移除 Transfer-Encoding、Expect，并关闭 Keep-Alive。响应保留原始压缩正文及对应头，不自动解压。请求和响应完整缓冲到内存，不是流式传输。可定位正文流读取后恢复原位置，不可定位流会被消耗，不能依赖它进行重定向重放。

当前 Swoole 6.2.3 原生 HTTP 客户端在“103 Early Hints 后接 200”检查中接收失败，因此 Client 不实现 `Psr\Http\Client\ClientInterface`，不能作为完整 PSR-18 客户端交给依赖方。原生客户端未暴露的响应元数据与重复头信息不保证完整保留，Set-Cookie 单独处理。

暂不支持连接池、大文件流式传输、multipart、代理、HTTP/2、WebSocket、自动 Cookie 会话和自动重试。

## 数组选项迁移

当前尚未发布稳定 tag，本次统一改用配置对象，不保留数组接口：

- `new Client(['timeout' => 5])` → `new Client(new RequestOptions(timeout: 5.0))`。
- `get($url, ['query' => $query])` → `get($url, new RequestOptions(query: $query))`。
- `form_params/connect_timeout/allow_redirects/base_uri` → `formParams/connectTimeout/allowRedirects/baseUri`。
- 自定义中间件和传输器的 options 参数改为 `RequestOptions`，扩展值放 `attributes`。
- 项目的数组正文约定、日志脱敏与 Result 转换继续留在项目适配层。

参考：[PSR-7](https://www.php-fig.org/psr/psr-7/)、[PSR-18](https://www.php-fig.org/psr/psr-18/)、[Guzzle Handler/Middleware](https://docs.guzzlephp.org/en/stable/handlers-and-middleware.html)。
