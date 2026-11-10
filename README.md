# Swoole HTTP Client

基于 `Swoole\Coroutine\Http\Client` 的轻量 Composer 库，要求 PHP 8.1+、Swoole 5.0+，HTTPS 需要 Swoole 编译启用 OpenSSL。

不使用连接池，不自动重试，每次请求完整接收响应后关闭连接。客户端实例不存储请求过程状态，可供多个协程复用；回调捕获的外部状态需由调用方保证协程安全。不依赖业务框架、日志组件或全局函数。

## 安装

包发布到 Composer 仓库后使用 `composer require haoa/swoole-http-client`。本地开发可在使用方 `composer.json` 中配置 path 仓库：

```json
{
  "repositories": [
    {"type": "path", "url": "../hao-php/swoole-http-client"}
  ],
  "require": {"haoa/swoole-http-client": "@dev"}
}
```

仓库路径相对于使用方项目；Docker 内需保证目标目录可访问。尚未发布正式版本。

## 请求

```php
use Haoa\SwooleHttpClient\Client;

Swoole\Coroutine\run(function (): void {
    $client = new Client(['timeout' => 5.0]);
    $response = $client->get('https://example.com/items?page=1', ['page' => 2]);
    $response = $client->postJson('https://example.com/items', ['name' => '示例']);
    $response = $client->postForm('https://example.com/login', ['username' => '示例']);
    $response = $client->request('PATCH', 'https://example.com/items/1', [
        'headers' => ['Authorization' => 'Bearer 示例'],
        'query' => ['source' => 'admin'],
        'body' => ['name' => '修改'],
    ]);
    echo $response->getStatusCode();
    echo $response->getBody();
    $data = $response->json();
});
```

在已经处于协程中的服务内直接调用，不要嵌套 `Coroutine\run()`。`request()` 支持 PUT、PATCH、DELETE、HEAD、OPTIONS 等方法。

| 选项 | 默认值 | 说明 |
| --- | --- | --- |
| `headers` | `[]` | 请求头名称不区分大小写，单次请求覆盖默认同名头 |
| `query` | `[]` | 合并 URL 查询参数，同名键覆盖；数组按 PHP 查询格式编码 |
| `body` | `[]` | 仅接受数组，GET/HEAD 作为查询参数，显式 query 优先 |
| `content_type` | `application/json` | 请求头 Content-Type 优先，支持 JSON、`+json`、URL 编码表单 |
| `timeout` | `5.0` | 正数秒，整个重定向链共享超时预算 |
| `redirect` | `3` | 最多跟随次数，范围 0–10；0 直接返回重定向响应 |
| `ssl_cafile` | 系统默认 | 自定义 CA 文件，始终校验证书与目标主机 |
| `before` | 无 | `function (string $method, string $url, array $headers, array $body): void` |
| `after` | 无 | `function (Response $response): void` |

`before/after` 针对每一跳执行；before 的头为调用层请求头，不包含传输层补充的 Host/Content-Length。回调异常原样向上传播，网络失败不执行 after。库不记录日志，调用方负责日志、脱敏和业务 Result 转换。

正文默认为 JSON，空 POST/PUT/PATCH 编码为 `[]`；空表单为空字符串。暂不支持原始字符串正文、文件上传下载、流式响应、HTTP/2、WebSocket 和 Cookie 会话保存。

## 响应与异常

响应提供只读 `statusCode`、`headers`、`body`、`url`，以及 `getStatusCode()`、`getHeaders()`、`getBody()`、`json()`。响应头名称为小写；Set-Cookie 保存为数组，不会自动携带到后续请求。

HTTP 4xx/5xx 正常返回响应，由调用方判断。网络失败抛出 `RequestException`，`getCode()` 为 Swoole 错误码，`transportStatus` 为 Swoole 传输状态码。无效参数抛出 `InvalidArgumentException`，协程外调用抛出 `LogicException`，JSON 编解码错误抛出 `JsonException`。

重定向只识别 301、302、303、307、308。301/302 的 POST 和 303 的非 HEAD 请求改为 GET；307/308 保留方法和正文。跨源跳转只保留 Accept、Accept-Encoding、Accept-Language、User-Agent、Content-Type；调用方可用 `redirect => 0` 自行处理。超过次数抛异常，不自动重试写请求。

响应正文完整保存在内存，适用于普通接口调用，不适合大文件传输。
