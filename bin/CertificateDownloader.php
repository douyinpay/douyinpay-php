#!/usr/bin/env php
<?php declare(strict_types=1);

// load autoload.php
$possibleFiles = [__DIR__.'/../vendor/autoload.php', __DIR__.'/../../../autoload.php', __DIR__.'/../../autoload.php'];
$file = null;
foreach ($possibleFiles as $possibleFile) {
    if (\file_exists($possibleFile)) {
        $file = $possibleFile;
        break;
    }
}
if (null === $file) {
    throw new \RuntimeException('Unable to locate autoload.php file.');
}

require_once $file;
unset($possibleFiles, $possibleFile, $file);

use DouYinPay\AesUtil;
use GuzzleHttp\Middleware;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use DouYinPay\Builder;
use DouYinPay\ClientDecoratorInterface;

 /**
  * CertificateDownloader class
  */
class CertificateDownloader
{
     private const DEFAULT_BASE_URI = 'https://api.douyinpay.com/';
    private const GET_PLATFORM_CERTS_PATH = '/v1/merchant/certificates/getPlatformCertificates';

    public function run(): void
    {
        $opts = $this->parseOpts();

        if (!$opts || isset($opts['help'])) {
            $this->printHelp();
            return;
        }
        if (isset($opts['version'])) {
            self::prompt(ClientDecoratorInterface::VERSION);
            return;
        }
        $this->job($opts);
    }

    /**
     * Before `verifier` executing, decrypt and put the platform certificate(s) into the `$certs` reference.
     *
     * @param string $encryptKey
     * @param array<string,?string> $certs
     *
     * @return callable(ResponseInterface)
     */
    private static function certsInjector(string $encryptKey, array &$certs): callable {
        return static function(ResponseInterface $response) use ($encryptKey, &$certs): ResponseInterface {
            // 解析响应体 JSON，提取 data 列表
            $body = (string) $response->getBody();
            $json = \json_decode($body);
            $data = \is_object($json) && isset($json->certificates) && \is_array($json->certificates) ? $json->certificates : [];
            // 遍历每条证书数据，使用 API 密钥解密平台证书内容，并按序列号索引存入 $certs
            \array_map(static function($row) use ($encryptKey, &$certs) {
                $cert = $row->encrypt_certificate;
                $add = $cert->associated_data ?? '';
                $certs[$row->cert_no] = AesUtil::decrypt($cert->cipher_text, $encryptKey, $cert->nonce, $add); //associated_data未返回该字段
            }, $data);

            // 保持响应对象不变，交给下游中间件（verifier 与 recorder）
            return $response;
        };
    }

    /**
     * @param array<string,string|true> $opts
     *
     * @return void
     */
    private function job(array $opts): void
    {
        static $certs = ['any' => null];

        // 输出目录与 API 密钥
        $outputDir = $opts['output'] ?? \sys_get_temp_dir();
        $encryptKey = (string) $opts['key'];

        // 构建客户端装饰器实例：传入商户信息、私钥、平台证书引用与接入点
        $instance = Builder::factory([
            'mchid'      => $opts['mchid'],
            'serial'     => $opts['serialno'],
            'privateKey' => \file_get_contents((string)$opts['privatekey']),
            'certs'      => &$certs,
            'base_uri'   => (string)($opts['baseuri'] ?? self::DEFAULT_BASE_URI),
        ]);

        /** @var \GuzzleHttp\HandlerStack $stack */
        $stack = $instance->getDriver()->select(ClientDecoratorInterface::JSON_BASED)->getConfig('handler');
        // 响应中间件按 FILO（先进后出）顺序执行：injector -> verifier -> recorder
        $stack->after('verifier', Middleware::mapResponse(self::certsInjector($encryptKey, $certs)), 'injector');
        $stack->before('verifier', Middleware::mapResponse(self::certsRecorder((string) $outputDir, $certs)), 'recorder');

        // 发起证书下载请求，附带调试开关；异常时打印错误与响应体
        $instance->chain(self::GET_PLATFORM_CERTS_PATH)->getAsync(
            ['debug' => true]
        )->otherwise(static function($exception) {
            self::prompt($exception->getMessage());
            if ($exception instanceof RequestException && $exception->hasResponse()) {
                /** @var ResponseInterface $response */
                $response = $exception->getResponse();
                self::prompt((string) $response->getBody(), '', '');
            }
            self::prompt($exception->getTraceAsString());
        })->wait();
    }

    /**
     * After `verifier` executed, wrote the platform certificate(s) onto disk.
     *
     * @param string $outputDir
     * @param array<string,?string> $certs
     *
     * @return callable(ResponseInterface)
     */
    private static function certsRecorder(string $outputDir, array &$certs): callable {
        return static function(ResponseInterface $response) use ($outputDir, &$certs): ResponseInterface {
            // 解析响应体，遍历每条证书，输出关键信息并写入目标目录
            $body = (string) $response->getBody();
            $json = \json_decode($body);
            $data = \is_object($json) && isset($json->certificates) && \is_array($json->certificates) ? $json->certificates : [];
            \array_walk($data, static function($row, $index, $certs) use ($outputDir) {
                $serialNo = $row->cert_no;
                $outpath = $outputDir . \DIRECTORY_SEPARATOR . 'DouYinPay_' . $serialNo . '.pem';
                self::prompt(
                    'Certificate #' . $index . ' {',
                    '    Serial Number: ' . self::highlight($serialNo),
                    '    Not Before: ' . (new \DateTime($row->effective_time))->format(\DateTime::W3C),
                    '    Not After: ' . (new \DateTime($row->expire_time))->format(\DateTime::W3C),
                    '    Saved to: ' . self::highlight($outpath),
                    '    You may confirm the above infos again even if this library already did(by Crypto\Rsa::verify):',
                    '      ' . self::highlight(\sprintf('openssl x509 -in %s -noout -serial -dates', $outpath)),
                    '    Content: ', '', $certs[$serialNo] ?? '', '',
                    '}'
                );

                // 写入 PEM 内容到目标文件
                \file_put_contents($outpath, $certs[$serialNo]);
            }, $certs);

            return $response;
        };
    }

    /**
     * @param string $thing
     */
    private static function highlight(string $thing): string
    {
        return \sprintf("\x1B[1;32m%s\x1B[0m", $thing);
    }

    /**
     * @param string $messages
     */
    private static function prompt(...$messages): void
    {
        // 逐行打印消息到终端
        \array_walk($messages, static function (string $message): void { \printf('%s%s', $message, \PHP_EOL); });
    }

    /**
     * @return ?array<string,string|true>
     */
    private function parseOpts(): ?array
    {
        $opts = [
            [ 'key', 'k', true ],
            [ 'mchid', 'm', true ],
            [ 'privatekey', 'f', true ],
            [ 'serialno', 's', true ],
            [ 'output', 'o', false ],
            [ 'baseuri', 'u', false ],
        ];

        $shortopts = 'hV';
        $longopts = [ 'help', 'version' ];
        foreach ($opts as $opt) {
            [$key, $alias] = $opt;
            $shortopts .= $alias . ':';
            $longopts[] = $key . ':';
        }
        $parsed = \getopt($shortopts, $longopts);

        if (!$parsed) {
            return null;
        }

        $args = [];
        foreach ($opts as $opt) {
            [$key, $alias, $mandatory] = $opt;
            if (isset($parsed[$key]) || isset($parsed[$alias])) {
                /** @var string|string[] $possible */
                $possible = $parsed[$key] ?? $parsed[$alias] ?? '';
                $args[$key] = \is_array($possible) ? $possible[0] : $possible;
            } elseif ($mandatory) {
                return null;
            }
        }

        if (isset($parsed['h']) || isset($parsed['help'])) {
            $args['help'] = true;
        }
        if (isset($parsed['V']) || isset($parsed['version'])) {
            $args['version'] = true;
        }
        return $args;
    }

    private function printHelp(): void
    {
        // 打印命令行用法说明与参数含义
        self::prompt(
            'Usage: 抖音支付平台证书下载工具 [-hV]',
            '                    -f=<privateKeyFilePath> -k=<encryptKey> -m=<merchantId>',
            '                    -s=<serialNo> -o=[outputFilePath] -u=[baseUri]',
            'Options:',
            '  -m, --mchid=<merchantId>   商户号',
            '  -s, --serialno=<serialNo>  商户证书的序列号',
            '  -f, --privatekey=<privateKeyFilePath>',
            '                             商户的私钥文件',
            '  -k, --key=<encryptKey>       API密钥',
            '  -o, --output=[outputFilePath]',
            '                             下载成功后保存证书的路径，可选，默认为临时文件目录夹',
            '  -u, --baseuri=[baseUri]    接入点，可选，默认为 ' . self::DEFAULT_BASE_URI,
            '  -V, --version              Print version information and exit.',
            '  -h, --help                 Show this help message and exit.', ''
        );
    }
}

// main
(new CertificateDownloader())->run();
