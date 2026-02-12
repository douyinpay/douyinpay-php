<?php declare(strict_types=1);

namespace DouYinPay;

use function array_replace_recursive;
use function call_user_func;
use function sprintf;
use function php_uname;
use function implode;
use function strncasecmp;
use function strcasecmp;
use function substr;
use function constant;
use function defined;

use const PHP_OS;
use const PHP_VERSION;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\UriTemplate\UriTemplate;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Decorate the `GuzzleHttp\Client` instance
 */
final class ClientDecorator implements ClientDecoratorInterface
{
    use ClientJsonTrait;

    /**
     * @var ClientInterface - The API's `\GuzzleHttp\Client`
     */
    protected $v1;

    /**
     * Deep merge the input with the defaults
     *
     * @param array<string,string|int|bool|array|mixed> $config - The configuration.
     *
     * @return array<string, string|mixed> - With the built-in configuration.
     */
    protected static function withDefaults(array ...$config): array
    {
        // 叠加默认配置与自定义配置，并注入统一的 User-Agent 头
        return array_replace_recursive(static::$defaults, ['headers' => static::userAgent()], ...$config);
    }

    /**
     * Prepare the `User-Agent` value key/value pair
     *
     * @return array<string, string>
     */
    protected static function userAgent(): array
    {
        // 统一构造 User-Agent，包含 SDK、Guzzle、curl、系统与 PHP 版本信息
        return ['User-Agent' => implode(' ', [
            sprintf('douyinpay-php/%s', static::VERSION),
            sprintf('GuzzleHttp/%s', constant(ClientInterface::class . (defined(ClientInterface::class . '::VERSION') ? '::VERSION' : '::MAJOR_VERSION'))),
            sprintf('curl/%s', ((array)call_user_func('\curl_version'))['version'] ?? 'unknown'),
            sprintf('(%s/%s)', PHP_OS, php_uname('r')),
            sprintf('PHP/%s', PHP_VERSION),
        ])];
    }

    /**
     * Taken body string
     *
     * @param MessageInterface $message - The message
     */
    protected static function body(MessageInterface $message): string
    {
        // 读取流内容并在读取后将指针复位，避免影响后续读取
        $stream = $message->getBody();
        $content = (string) $stream;

        $stream->tell() && $stream->rewind();

        return $content;
    }


    public function __construct(array $config = [])
    {
        $this->{static::JSON_BASED} = static::jsonBased($config);
    }

    /**
     * Identify the `protocol` and `uri`
     *
     * @param string $uri - The uri string.
     *
     * @return string[] - the first element is the API version aka `protocol`, the second is the real `uri`
     */
    private static function prepare(string $uri): array
    {
        // 识别协议前缀（v1匹配到json)，并返回真实路径名
        return [static::JSON_BASED, $uri];
    }

    /**
     * @inheritDoc
     */
    public function select(?string $protocol = null): ClientInterface
    {
        return $this->{static::JSON_BASED};
    }

    /**
     * @inheritDoc
     */
    public function request(string $method, string $uri, array $options = []): ResponseInterface
    {
        // 支持 UriTemplate 展开，并根据协议选择对应客户端
        [$protocol, $pathname] = self::prepare(UriTemplate::expand($uri, $options));

        return $this->select($protocol)->request($method, $pathname, $options);
    }

    /**
     * @inheritDoc
     */
    public function requestAsync(string $method, string $uri, array $options = []): PromiseInterface
    {
        // 异步请求同样支持 UriTemplate 展开与协议选择
        [$protocol, $pathname] = self::prepare(UriTemplate::expand($uri, $options));

        return $this->select($protocol)->requestAsync($method, $pathname, $options);
    }
}
