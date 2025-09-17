<?php

namespace GUOGUO882010\EastMoney;

/**
 * 简洁安全的 HTTPS 客户端（PHP 7.1+）
 * 用法见文末示例
 */
class HttpClient
{
    private $timeout;
    private $connectTimeout;
    private $verifyPeer;
    private $verifyHost;
    private $caPathOrFile; // 可选：自定义 CA 证书路径/文件
    private $followLocation;
    private $maxRedirects;
    private $userAgent;

    public function __construct(array $options = [])
    {
        $this->timeout        = $options['timeout']         ?? 15;
        $this->connectTimeout = $options['connect_timeout'] ?? 5;
        $this->verifyPeer     = $options['verify_peer']     ?? true;
        $this->verifyHost     = $options['verify_host']     ?? 2; // 0/1/2
        $this->caPathOrFile   = $options['ca']              ??  __DIR__ . '/curl_ca_cert.pem'; // 目录或文件
        $this->followLocation = $options['follow_location'] ?? true;
        $this->maxRedirects   = $options['max_redirects']   ?? 5;
        $this->userAgent      = $options['user_agent']      ?? 'HttpClient/1.0 (+curl; PHP)';
    }

    /**
     * 发送 GET 请求
     */
    public function get(string $url, array $query = [], array $headers = [])
    {
        if (!empty($query)) {
            $qs  = http_build_query($query);
            $url .= (strpos($url, '?') === false ? '?' : '&') . $qs;
        }
        return $this->request('GET', $url, null, $headers);
    }

    /**
     * 发送 POST 请求
     * $data:
     *   - $asJson=false 时：数组将按 application/x-www-form-urlencoded 发送
     *   - $asJson=true  时：数组/标量将按 application/json 发送
     */
    public function post(string $url, $data = [], array $headers = [], bool $asJson = false)
    {
        $body = null;
        $hdrs = $headers;

        if ($asJson) {
            if (!self::hasHeader($hdrs, 'Content-Type')) {
                $hdrs[] = 'Content-Type: application/json; charset=utf-8';
            }
            $body = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
        } else {
            // 表单或原始字符串
            if (is_array($data)) {
                $body = http_build_query($data);
                if (!self::hasHeader($hdrs, 'Content-Type')) {
                    $hdrs[] = 'Content-Type: application/x-www-form-urlencoded; charset=utf-8';
                }
            } else {
                $body = (string)$data;
            }
        }

        return $this->request('POST', $url, $body, $hdrs);
    }

    /**
     * 发送 multipart/form-data（含文件）
     * 例：$fields = ['name' => 'abc', 'file' => new CURLFile('/path/a.png', 'image/png', 'a.png')]
     */
    public function postMultipart(string $url, array $fields, array $headers = [])
    {
        // cURL 会自动设置 multipart 边界与 Content-Type
        // 确保没有自己手动设置 Content-Type
        $headers = array_values(array_filter($headers, function ($h) {
            return stripos($h, 'Content-Type:') !== 0;
        }));

        return $this->request('POST', $url, $fields, $headers, true);
    }

    /**
     * 核心请求方法
     */
    public function request(string $method, string $url, $body = null, array $headers = [], bool $isMultipart = false)
    {
        $ch = curl_init();
        if ($ch === false) {
            return $this->errorResult('Failed to init curl');
        }

        $responseHeaders = [];
        $headerFn = function ($ch, $headerLine) use (&$responseHeaders) {
            $trim = trim($headerLine);
            if ($trim === '' || stripos($trim, 'HTTP/') === 0) {
                // 跳过状态行或空行
                return strlen($headerLine);
            }
            $pos = strpos($headerLine, ':');
            if ($pos !== false) {
                $name  = trim(substr($headerLine, 0, $pos));
                $value = trim(substr($headerLine, $pos + 1));
                $lname = strtolower($name);
                // 合并同名响应头
                if (!isset($responseHeaders[$name])) {
                    $responseHeaders[$name] = $value;
                } else {
                    if (is_array($responseHeaders[$name])) {
                        $responseHeaders[$name][] = $value;
                    } else {
                        $responseHeaders[$name] = [$responseHeaders[$name], $value];
                    }
                }
            }
            return strlen($headerLine);
        };

        $curlOpts = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_HEADERFUNCTION => $headerFn,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_FOLLOWLOCATION => $this->followLocation,
            CURLOPT_MAXREDIRS      => $this->maxRedirects,
            CURLOPT_ACCEPT_ENCODING=> '', // 允许 gzip/deflate/br（由 cURL 自动处理）
            CURLOPT_USERAGENT      => $this->userAgent,
        ];

        // SSL 相关（HTTPS）
        $curlOpts[CURLOPT_SSL_VERIFYPEER] = $this->verifyPeer ? 1 : 0;
        $curlOpts[CURLOPT_SSL_VERIFYHOST] = $this->verifyHost; // 通常为2
        if ($this->caPathOrFile) {
            if (is_dir($this->caPathOrFile)) {
                $curlOpts[CURLOPT_CAPATH] = $this->caPathOrFile;
            } elseif (is_file($this->caPathOrFile)) {
                $curlOpts[CURLOPT_CAINFO] = $this->caPathOrFile;
            }
        }

        // 处理请求头
        if (!self::hasHeader($headers, 'Accept')) {
            $headers[] = 'Accept: */*';
        }
        $curlOpts[CURLOPT_HTTPHEADER] = $headers;

        // 处理请求体
        if ($body !== null) {
            if ($isMultipart) {
                // $body 是数组（含 CURLFile）
                $curlOpts[CURLOPT_POSTFIELDS] = $body;
            } else {
                // 原始字符串或已编码表单
                $curlOpts[CURLOPT_POSTFIELDS] = $body;
            }
        }

        curl_setopt_array($ch, $curlOpts);
        $respBody = curl_exec($ch);

        $errno    = curl_errno($ch);
        $errstr   = curl_error($ch);
        $status   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno) {
            return $this->errorResult($errstr, $errno, $status, $responseHeaders);
        }

        return [
            'ok'        => ($status >= 200 && $status < 400),
            'status'    => $status,
            'headers'   => $responseHeaders,
            'body'      => $respBody,
            'error'     => null,
            'errno'     => 0,
        ];
    }

    private static function hasHeader(array $headers, string $name): bool
    {
        $lname = strtolower($name);
        foreach ($headers as $h) {
            if (stripos($h, $name . ':') === 0) return true;
            // 兼容大小写不同
            $pos = strpos($h, ':');
            if ($pos !== false) {
                if (strtolower(substr($h, 0, $pos)) === $lname) return true;
            }
        }
        return false;
    }

    private function errorResult(string $message, int $errno = 0, int $status = 0, array $headers = [])
    {
        return [
            'ok'        => false,
            'status'    => $status,
            'headers'   => $headers,
            'body'      => null,
            'error'     => $message,
            'errno'     => $errno,
        ];
    }
}

/** ---------------- 使用示例 ---------------- */
# 严格校验证书（推荐），并跟随重定向
/*$client = new HttpClient([
    'timeout'         => 15,
    'connect_timeout' => 5,
    'verify_peer'     => true,
    'verify_host'     => 2,
    // 'ca' => '/etc/ssl/certs',  // 自定义 CA 目录或文件（可选）
]);*/

// 1) GET 请求
// $res = $client->get('https://httpbin.org/get', ['q' => 'php'], ['Accept: application/json']);

// 2) POST 表单
// $res = $client->post('https://httpbin.org/post', ['name' => 'Alice', 'age' => 20]);

// 3) POST JSON
// $res = $client->post('https://httpbin.org/post', ['name' => 'Bob'], ['X-Token: 123'], true);

// 4) Multipart（文件上传）
// $file = new CURLFile('/path/to/a.png', 'image/png', 'a.png');
// $res  = $client->postMultipart('https://httpbin.org/post', ['file' => $file, 'desc' => 'logo']);

// 5) 读取结果
// if ($res['ok']) {
//     echo "Status: {$res['status']}\n";
//     print_r($res['headers']);
//     echo $res['body'];
// } else {
//     echo "HTTP Error: {$res['status']} cURL[{$res['errno']}]: {$res['error']}\n";
// }
