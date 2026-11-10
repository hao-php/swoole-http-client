<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/** 将便捷请求选项转换为标准 PSR-7 请求。 */
final class RequestFactory
{
    /**
     * 构建请求，正文格式由明确选项决定，不根据方法猜测。
     * @param string $method HTTP 方法
     * @param string $url 绝对地址或相对 base_uri 的引用
     * @param array $options 已合并的请求选项
     */
    public static function create(string $method, string $url, array $options): RequestInterface
    {
        $uri = new Uri($url);
        if (isset($options['base_uri'])) {
            $uri = UriResolver::resolve(new Uri($options['base_uri']), $uri);
        }
        $uri = $uri->withFragment('');
        if (isset($options['query'])) {
            if (is_string($options['query'])) {
                // 字符串保留重复键及调用方编码，作为完整查询部分。
                $uri = $uri->withQuery($options['query']);
            } elseif ($options['query'] !== []) {
                parse_str($uri->getQuery(), $existing);
                $uri = $uri->withQuery(http_build_query(array_replace($existing, $options['query']), '', '&', PHP_QUERY_RFC3986));
            }
        }
        $headers = $options['headers'];
        $hasContentType = array_key_exists('content-type', array_change_key_case($headers, CASE_LOWER));
        $body = $options['body'] ?? '';
        if (array_key_exists('json', $options)) {
            $body = json_encode($options['json'], JSON_THROW_ON_ERROR);
            if (!$hasContentType) {
                $headers['Content-Type'] = 'application/json';
            }
        } elseif (array_key_exists('form_params', $options)) {
            $body = http_build_query($options['form_params'], '', '&', PHP_QUERY_RFC3986);
            if (!$hasContentType) {
                $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            }
        } elseif (!is_string($body) && !is_resource($body) && !$body instanceof StreamInterface) {
            throw new \InvalidArgumentException('body 必须为字符串、资源或 PSR-7 流；数组请使用 json 或 form_params');
        }
        return new Request($method, $uri, $headers, $body);
    }
}
