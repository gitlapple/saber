<?php
/**
 * Copyright: Swlib
 * Author: Twosee <twose@qq.com>
 * Date: 2018/3/24 下午8:43
 */

namespace Swlib\Saber;

use Swlib\Http\BufferStream;
use Swlib\Http\ContentType;
use Swlib\Http\Uri;

/**
 * @method Request|Response get(string | string $uri, array $options = [])
 * @method Request|Response head(string | string $uri, array $options = [])
 * @method Request|Response put(string | string $uri, $data = null, array $options = [])
 * @method Request|Response post(string | string $uri, $data = null, array $options = [])
 * @method Request|Response patch(string | string $uri, $data = null, array $options = [])
 * @method Request|Response delete(string | string $uri, array $options = [])
 */
class Client
{

    private static $default_options = [
        'timeout' => 5.000,
        'proxy' => null,
        'ssl' => Request::SSL_AUTO,
        'cafile' => __DIR__ . '/cacert.pem',
        'ssl_verify_peer' => false,
        'ssl_host_name' => null,
        'ssl_allow_self_signed' => true,
        'protocol_version' => '1.1',
        'method' => 'GET',
        'base_uri' => null,
        'uri' => null,
        'headers' => [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
            'Accept-Encoding' => 'gzip'
        ],
        'cookies' => false,
        'useragent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/63.0.3239.132 Safari/537.36',
        'content_type' => ContentType::URLENCODE,
        'redirect' => 3,
        'keep_alive' => true,
        'form_data' => null,
        'data' => null,
        'before' => [],
        'after' => []
    ];

    private static $default_template_request;

    private static function getTemplateRequestCopy(): Request
    {
        if (!isset(self::$default_template_request)) {
            self::$default_template_request = new Request();
            self::transOptionsToRequest(
                self::$default_options,
                self::$default_template_request
            );
        }

        return clone self::$default_template_request;
    }

    public static function create(array $options = []): self
    {
        return new static($options);
    }

    public static function session(array $options = []): self
    {
        $client = new static();
        $client->session = true;
        $options = self::mergeOptions($options, [
            'before' => [
                function (Request $request) use ($client) {
                    $request->cookies->adds($client->raw->cookies);
                }
            ],
            'after' => [
                function (Response $response) use ($client) {
                    $client->raw->cookies->adds($response->cookies);
                    $client->raw->incremental_cookies->adds($response->cookies);
                }
            ]
        ]);

        return $client->setOptions($options);
    }

    private static function mergeOptions(array $options, array $default)
    {
        static $special_fields;
        static $special_fields_length;
        if (!isset($special_fields)) {
            $special_fields = [];
            foreach (self::$default_options as $key => $val) {
                if (is_array($val)) {
                    $special_fields[$key] = $key;
                }
            }
            $special_fields_length = count($special_fields);
        }
        if (count($options) > $special_fields_length) {
            foreach ($special_fields as $field) {
                if (isset($options[$field])) {
                    $options[$field] = array_merge($default[$field] ?? [], $options[$field]);
                }
            }
        } else {
            foreach ($options as $key => $val) {
                if (isset($special_fields[$key])) {
                    $options[$key] = array_merge($default[$key] ?? [], $options[$key]);
                }
            }
        }

        return $options + $default;
    }

    public static function mergeData($default, $add): array
    {
        if (is_string($default)) {
            parse_str($default, $default);
        }
        if (is_string($add)) {
            parse_str($add, $add);
        }

        /** @var $add array */
        /** @var $default array */
        return $add + $default;
    }

    private $raw;
    private $options = [];
    private $session = false;
    private $_wait = false;

    private function __construct(array $options = [])
    {
        $this->setOptions(
            $this->options = $options,
            $this->raw = $this->getTemplateRequestCopy()
        );
    }

    public function __call($name, $arguments)
    {
        if (empty($arguments[0])) {
            throw new \InvalidArgumentException('Uri should not be empty!');
        }
        $def = [
            'uri' => $arguments[0],
            'method' => $name,
        ];
        $name = strtoupper($name);
        switch ($name) {
            case 'GET':
            case 'HEAD':
            case 'DELETE':
                {
                    $options = $arguments[1] ?? [];

                    return $this->request($def + $options);
                }
            case 'POST':
            case 'PUT':
            case 'PATCH':
                {
                    if (isset($arguments[1])) {
                        $def += ['data' => $arguments[1]];
                    }
                    $options = $arguments[2] ?? [];

                    return $this->request($def + $options);
                }
            default:
                throw new \BadMethodCallException("Method '{$name}' not exist!");
        }
    }

    /**
     * @param array $options
     * @return Request|Response
     */
    public function request(array $options)
    {
        $request = clone $this->raw;
        $this->setOptions($options, $request);

        /** Psr style */
        if ($options['psr'] ?? false) {
            return $request;
        }

        $request->exec();

        if ($options['wait'] ?? false || $this->_wait) {
            $this->_wait = false;
            return $request;
        }

        return $request->recv();
    }

    /**
     * @param array $options
     * @param null|Request $request
     * @return $this
     */
    public function setOptions(array $options = [], ?Request $request = null): self
    {
        if (empty($options)) {
            return $this;
        }
        if ($request === null || $request === $this->raw) {
            $request = $this->raw;
            $this->options += self::mergeOptions($options, $this->options);
        }
        $options += [
            'base_uri' => $this->options['base_uri'] ?? null,
            'uri' => $this->options['uri'] ?? null
        ];
        if ($this->session) {
            $options['session'] = $this->raw->cookies;
        }
        self::transOptionsToRequest($options, $request);

        return $this;
    }

    public static function transAlias(array &$options)
    {
        static $aliasMap = [
            0 => 'method',
            1 => 'uri',
            2 => 'data',
            'base_url' => 'base_uri',
            'url' => 'uri',
            'callback' => 'after',
        ];

        if (count($options) > count($aliasMap)) {
            foreach ($aliasMap as $alias => $raw_key) {
                if (isset($options[$alias])) {
                    $options[$raw_key] = &$options[$alias];
                }
            }
        } else {
            foreach ($options as $key => &$val) {
                if (isset($aliasMap[$key])) {
                    $options[$aliasMap[$key]] = &$val;
                }
            }
        }
    }

    public static function transOptionsToRequest(array $options, Request $request)
    {
        if (empty($options)) {
            return;
        }
        self::transAlias($options);

        if (isset($options['base_uri']) || isset($options['uri'])) {
            $request->withUri(
                Uri::resolve($options['base_uri'] ?? null, $options['uri'] ?? null)
            );
        }

        if (!empty($options['form_data']) && $uri = $request->getUri()) {
            $uri->withQuery(http_build_query($options['form_data']));
        }

        /** 设置请求方法 */
        if (isset($options['method'])) {
            $request->withMethod($options['method']);
        }

        /** 设置请求的数据 */
        if (isset($options['content_type'])) {
            $request->withHeader('Content-type', $options['content_type']);
        }
        if (!empty($options['data'])) {
            if (!is_string($options['data'])) {
                switch ($options['content_type']) {
                    case ContentType::JSON:
                        $options['data'] = json_encode($options['data']);
                        break;
                    case ContentType::XML:
                        throw new \BadMethodCallException('XML-encoder not implemented');
                        break;
                    case ContentType::URLENCODE:
                    default:
                        $options['data'] = http_build_query($options['data']);
                }
            }
        } else {
            $options['data'] = null;
        }
        $buffer = $options['data'] ? new BufferStream($options['data']) : null;
        if (isset($buffer)) {
            $request->withBody($buffer);
        }

        /** （可能的）HTTPS 连接证书 */
        if (isset($options['ssl'])) {
            $request->withSSL($options['ssl']);
        }
        if (isset($options['ssl_allow_self_signed'])) {
            $request->withSSLAllowSelfSigned($options['ssl_allow_self_signed']);
        }
        if (isset($options['ssl_verify_peer'])) {
            $request->withSSLVerifyPeer(
                $options['ssl_verify_peer'],
                $options['ssl_host_name'] ?? ''
            );
        }

        /** 设置超时 */
        if (isset($options['timeout'])) {
            $request->withTimeout($options['timeout']);
        }

        /** 是否跟踪重定向 */
        if (isset($options['redirect'])) {
            $request->withRedirect($options['redirect']);
        }

        /** 设置请求标头 */
        if (!empty($options['headers'])) {
            if (is_array($options['headers'])) {
                foreach ($options['headers'] as $key => $val) {
                    $request->withHeader($key, $val);
                }
            }
        }

        /** 设置COOKIE */
        if (!empty($options['cookies'])) {
            $cookies_instance = $options['session'] ?? $request->cookies;//FIXME
            $cookies_default = ($uri = $request->getUri()) ? ['domain' => $uri->getHost()] : [];
            //everything can be a Cookies object
            $cookies_instance->adds(
                $options['cookies'],
                $cookies_default,
                true
            );
        }

        /** 设置模拟的浏览器标识 */
        if (isset($options['useragent'])) {
            $request->withHeader('User-Agent', $options['useragent']);
        }

        /** 设置来源页面 */
        if (isset($options['referer'])) {
            $request->withHeader('Referer', $options['referer']);
        }

        if (isset($options['keep_alive'])) {
            $request->withKeepAlive($options['keep_alive']);
        }

        /** proxy 是否启用代理 */
        if (isset($options['proxy'])) {
            $parse = parse_url($options['proxy']);
            if ($parse['scheme'] === 'socks5') {
                $request->withSocks5(
                    $parse['host'], $parse['port'],
                    $parse['user'] ?? null, $parse['pass'] ?? null
                );
            } else {
                $request->withProxy($parse['host'], $parse['port']);
            }
        }

        /** 注册请求前前拦截器 */
        if (!empty($options['before'])) {
            $request->withAddedInterceptor('request', (array)$options['before']);
        }

        /** 注册回调函数 */
        if (!empty($options['after'])) {
            $request->withAddedInterceptor('response', (array)$options['after']);
        }
    }

    public function psr(array $options = []): Request
    {
        return $this->request(['psr' => true] + $options);
    }

    /** @return $this */
    public function wait(): self
    {
        $this->_wait = true;

        return $this;
    }

    /**
     * 并发请求
     *
     * @param array $requests
     * @param array $_options
     * @param array $data
     *
     * @return ResponseMap
     */
    public function requests(array $requests, array $default_options = []): ResponseMap
    {
        $req_queue = new RequestQueue(); //生成请求队列
        foreach ($requests as $index => $request_options) {
            $request_instance = clone $this->raw;
            $request_options = self::mergeOptions($request_options, $this->options);
            $this->setOptions($request_options, $request_instance);
            $req_queue->enqueue($request_instance);
        }

        return $req_queue->recv();
    }

}