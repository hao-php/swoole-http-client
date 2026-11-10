<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient;

/** 默认选项合并与通用配置校验。 */
final class RequestOptions
{
    /**
     * 合并选项，同名请求头大小写无关，正文选项互斥。
     * @param array $defaults 客户端默认选项
     * @param array $options 本次请求选项
     */
    public static function merge(array $defaults, array $options): array
    {
        $headers = [];
        foreach ([$defaults['headers'] ?? [], $options['headers'] ?? []] as $values) {
            if (!is_array($values)) {
                throw new \InvalidArgumentException('headers 必须为数组');
            }
            foreach ($values as $name => $value) {
                $headers[strtolower((string)$name)] = [(string)$name, $value];
            }
        }
        // 单次正文配置替换默认正文，避免默认 json 与本次 body 意外冲突。
        foreach (['body', 'json', 'form_params'] as $key) {
            if (array_key_exists($key, $options)) {
                unset($defaults['body'], $defaults['json'], $defaults['form_params']);
                break;
            }
        }
        $result = array_replace([
            'timeout' => 5.0,
            'connect_timeout' => 5.0,
            'allow_redirects' => 3,
            'verify' => true,
        ], $defaults, $options);
        $result['headers'] = [];
        foreach ($headers as [$name, $value]) {
            $result['headers'][$name] = $value;
        }
        unset($result['_deadline']);
        foreach (['timeout', 'connect_timeout'] as $key) {
            $value = $result[$key];
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value <= 0) {
                throw new \InvalidArgumentException($key . ' 必须为大于零的有限秒数');
            }
            $result[$key] = (float)$value;
        }
        if (is_bool($result['allow_redirects'])) {
            $result['allow_redirects'] = $result['allow_redirects'] ? 3 : 0;
        }
        if (!is_int($result['allow_redirects']) || $result['allow_redirects'] < 0) {
            throw new \InvalidArgumentException('allow_redirects 必须为非负整数或布尔值');
        }
        if (!is_bool($result['verify']) && !is_string($result['verify'])) {
            throw new \InvalidArgumentException('verify 必须为布尔值或 CA 文件路径');
        }
        if (is_string($result['verify']) && (!is_file($result['verify']) || !is_readable($result['verify']))) {
            throw new \InvalidArgumentException('CA 文件不存在或不可读');
        }
        $bodyOptions = array_intersect(['body', 'json', 'form_params'], array_keys($result));
        if (count($bodyOptions) > 1) {
            throw new \InvalidArgumentException('body、json、form_params 不能同时设置');
        }
        if (isset($result['query']) && !is_array($result['query']) && !is_string($result['query'])) {
            throw new \InvalidArgumentException('query 必须为数组或已编码字符串');
        }
        if (array_key_exists('form_params', $result) && !is_array($result['form_params'])) {
            throw new \InvalidArgumentException('form_params 必须为数组');
        }
        return $result;
    }
}
