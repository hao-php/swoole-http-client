<?php

declare(strict_types=1);

namespace Haoa\SwooleHttpClient;

/** URL 查询参数与重定向地址处理。 */
final class Url
{
    /**
     * 校验并拆分 HTTP 地址，不允许 URL 内携带账号密码。
     * @param string $url 请求地址
     */
    public static function parse(string $url): array
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('HTTP 地址包含非法字符');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('HTTP 地址无效');
        }
        $parts['scheme'] = strtolower($parts['scheme']);
        $parts['port'] ??= $parts['scheme'] === 'https' ? 443 : 80;
        if ($parts['port'] < 1 || $parts['port'] > 65535) {
            throw new \InvalidArgumentException('HTTP 端口无效');
        }
        return $parts;
    }

    /**
     * 合并查询参数，同名参数由数组覆盖；无追加参数时保留原始编码。
     * @param string $url 请求地址
     * @param array $query 查询参数
     */
    public static function mergeQuery(string $url, array $query): string
    {
        $url = explode('#', $url, 2)[0];
        if ($query === []) {
            return $url;
        }
        [$base, $existing] = array_pad(explode('?', $url, 2), 2, '');
        parse_str($existing, $values);
        $encoded = http_build_query(array_replace($values, $query), '', '&', PHP_QUERY_RFC3986);
        return $base . ($encoded === '' ? '' : '?' . $encoded);
    }

    /**
     * 生成用于跨源比较的协议、主机及有效端口。
     * @param string $url 请求地址
     */
    public static function origin(string $url): string
    {
        $parts = self::parse($url);
        return $parts['scheme'] . '://' . strtolower($parts['host']) . ':' . $parts['port'];
    }

    /**
     * 按相对引用规则解析重定向地址。
     * @param string $base 当前请求地址
     * @param string $location 重定向响应头
     */
    public static function resolve(string $base, string $location): string
    {
        $location = explode('#', trim($location), 2)[0];
        $parts = self::parse($base);
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $location)) {
            self::parse($location);
            return $location;
        }
        if (str_starts_with($location, '//')) {
            $url = $parts['scheme'] . ':' . $location;
            self::parse($url);
            return $url;
        }
        $authority = $parts['scheme'] . '://' . $parts['host'] . ':' . $parts['port'];
        $path = $parts['path'] ?? '/';
        if ($location === '') {
            return explode('#', $base, 2)[0];
        }
        if ($location[0] === '?') {
            return $authority . $path . $location;
        }
        [$target, $query] = array_pad(explode('?', $location, 2), 2, null);
        if (!str_starts_with($target, '/')) {
            $target = substr($path, 0, (int)strrpos($path, '/') + 1) . $target;
        }
        // RFC 3986 点路径消除，保留重复斜线和末尾斜线。
        $output = '';
        while ($target !== '') {
            if (str_starts_with($target, '../') || str_starts_with($target, './')) {
                $target = substr($target, strpos($target, '/') + 1);
            } elseif (str_starts_with($target, '/./') || $target === '/.') {
                $target = '/' . substr($target, 3);
            } elseif (str_starts_with($target, '/../') || $target === '/..') {
                $target = '/' . substr($target, 4);
                $output = substr($output, 0, (int)strrpos($output, '/'));
            } elseif ($target === '.' || $target === '..') {
                $target = '';
            } else {
                $length = strpos($target, '/', $target[0] === '/' ? 1 : 0);
                $length = $length === false ? strlen($target) : $length;
                $output .= substr($target, 0, $length);
                $target = substr($target, $length);
            }
        }
        $url = $authority . ($output ?: '/') . ($query === null ? '' : '?' . $query);
        self::parse($url);
        return $url;
    }
}
