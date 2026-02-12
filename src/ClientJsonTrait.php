<?php declare(strict_types=1);

namespace DouYinPay;

use function abs;
use function intval;
use function is_string;
use function is_resource;
use function is_object;
use function is_array;
use function implode;
use function count;
use function sprintf;
use function array_key_exists;
use function array_keys;

use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\MessageInterface;
use DouYinPay\Headers;

/** @var int - The maximum clock offset in second */
const MAXIMUM_CLOCK_OFFSET = 300;


/**
 * JSON based Client interface for sending HTTP requests.
 */
trait ClientJsonTrait
{
    /**
     * @var array<string, string|array<string, string>> - The defaults configuration whose pased in `GuzzleHttp\Client`.
     */
    protected static $defaults = [
        'base_uri' => 'https://api.douyinpay.com/',
        'headers' => [
            'Accept' => 'application/json, text/plain, application/x-gzip, application/pdf, image/png, image/*;q=0.5',
            'Content-Type' => 'application/json; charset=utf-8',
        ],
    ];

    abstract protected static function body(MessageInterface $message): string;

    abstract protected static function withDefaults(array ...$config): array;

    public static function signer(
        string $mchid,
        string $serial,
        #[\SensitiveParameter]
        $privateKey
    ): callable
    {
        return static function (RequestInterface $request) use ($mchid, $serial, $privateKey): RequestInterface {
            $nonce = Formatter::nonce();
            $timestamp = (string) Formatter::timestamp();
            $signature = Crypto\Rsa::sign(Formatter::request(
                $request->getMethod(), $request->getRequestTarget(), $timestamp, $nonce, static::body($request)
            ), $privateKey);

            $request = $request->withHeader('Authorization', Formatter::authorization(
                $mchid, $nonce, $signature, $timestamp, $serial
            ));
            return $request->withHeader(Headers::SdkAgent, Headers::SdkAgentVersion.$mchid);
        };
    }

    protected static function assertSuccessfulResponse(array &$certs): callable
    {
        return static function (ResponseInterface $response, RequestInterface $request) use(&$certs): ResponseInterface {
            // 响应验签前置：必须具备所需的四个响应头
            if (!($response->hasHeader(Headers::Nonce) && $response->hasHeader(Headers::Serial)
                && $response->hasHeader(Headers::Signature) && $response->hasHeader(Headers::Timestamp))) {
                throw new RequestException(sprintf(
                    Exception\DouYinPayException::ERR_RES_HEADERS_INCOMPLETE,
                    Headers::Nonce, Headers::Serial, Headers::Signature, Headers::Timestamp
                ), $request, $response);
            }

            // 获取请求路径与验签所需头部值
            [$nonce] = $response->getHeader(Headers::Nonce);
            [$serial] = $response->getHeader(Headers::Serial);
            [$signature] = $response->getHeader(Headers::Signature);
            [$timestamp] = $response->getHeader(Headers::Timestamp);

            // 校验本地时间与响应时间的最大偏移，防止重放
            $localTimestamp = Formatter::timestamp();

            if (abs($localTimestamp - intval($timestamp)) > MAXIMUM_CLOCK_OFFSET) {
                throw new RequestException(sprintf(
                    Exception\DouYinPayException::ERR_RES_HEADER_TIMESTAMP_OFFSET,
                    MAXIMUM_CLOCK_OFFSET, $timestamp, $localTimestamp
                ), $request, $response);
            }


            // 使用响应头中的平台证书序列号，需确保已存在对应证书
            if (!array_key_exists($serial, $certs)) {
                throw new RequestException(sprintf(
                    Exception\DouYinPayException::ERR_RES_HEADER_PLATFORM_SERIAL,
                    $serial, Headers::Serial, implode(',', array_keys($certs))
                ), $request, $response);
            }

            $verified = false;
            try {
                // 构造被验签的响应消息：时间戳、随机数以及响应正文或摘要
                $verified = Crypto\Rsa::verify(
                    Formatter::response(
                        $timestamp,
                        $nonce,
                        static::body($response)
                    ),
                    $signature, $certs[$serial]
                );
            } catch (\Exception $exception) {}
            // 验签失败：抛出异常并附带必要上下文信息
            if ($verified === false) {
                throw new RequestException(sprintf(
                    Exception\DouYinPayException::ERR_RES_HEADER_SIGNATURE_DIGEST,
                    $timestamp, $nonce, $signature, $serial
                ), $request, $response, $exception ?? null);
            }

            // 验签成功：透传响应
            return $response;
        };
    }

    public static function verifier(array &$certs): callable
    {
        // 生成断言回调，并在响应阶段挂载到处理链上
        $assert = static::assertSuccessfulResponse($certs);
        return static function (callable $handler) use ($assert): callable {
            return static function (RequestInterface $request, array $options = []) use ($assert, $handler): PromiseInterface {
                // 响应返回后进行验签与业务断言
                return $handler($request, $options)->then(static function(ResponseInterface $response) use ($assert, $request): ResponseInterface {
                    return $assert($response, $request);
                });
            };
        };
    }

    public static function jsonBased(array $config = []): Client
    {
        // 参数校验：商户号是否提供
        if (!(
           isset($config['mchid']) && is_string($config['mchid'])
        )) { throw new Exception\InvalidArgumentException(Exception\ERR_INIT_MCHID_IS_MANDATORY); }

        // 参数校验：商户证书序列号是否提供
        if (!(
            isset($config['serial']) && is_string($config['serial'])
        )) { throw new Exception\InvalidArgumentException(Exception\ERR_INIT_SERIAL_IS_MANDATORY); }

        // 参数校验：私钥类型是否合法（字符串/资源/对象）
        if (!(
            isset($config['privateKey']) && (is_string($config['privateKey']) || is_resource($config['privateKey']) || is_object($config['privateKey']))
        )) { throw new Exception\InvalidArgumentException(Exception\ERR_INIT_PRIVATEKEY_IS_MANDATORY); }

        // 参数校验：平台证书集合是否非空
        if (!(
            isset($config['certs']) && is_array($config['certs']) && count($config['certs'])
        )) { throw new Exception\InvalidArgumentException(Exception\ERR_INIT_CERTS_IS_MANDATORY); }

        // 平台证书集合不应包含商户证书序列号，避免混淆
        if (array_key_exists($config['serial'], $config['certs'])) {
            throw new Exception\InvalidArgumentException(sprintf(
                Exception\ERR_INIT_CERTS_EXCLUDE_MCHSERIAL, implode(',', array_keys($config['certs'])), $config['serial']
            ));
        }

        /** @var HandlerStack $stack */
        $stack = isset($config['handler']) && ($config['handler'] instanceof HandlerStack) ? (clone $config['handler']) : HandlerStack::create();
        // 请求阶段：在 prepare_body 之前插入签名中间件
        $stack->before('prepare_body', Middleware::mapRequest(static::signer($config['mchid'], $config['serial'], $config['privateKey'])), 'signer');
        // 响应阶段：在 http_errors 之前插入验签中间件
        $stack->before('http_errors', static::verifier($config['certs']), 'verifier');
        $config['handler'] = $stack;

        // 传递给 Guzzle 的配置需剔除敏感或非客户端参数
        unset($config['mchid'], $config['serial'], $config['privateKey'], $config['certs'], $config['secret'], $config['merchant']);

        return new Client(static::withDefaults($config));
    }
}
