<?php declare(strict_types=1);

namespace DouYinPay\Crypto;

use const OPENSSL_ALGO_SHA256;
use const OPENSSL_PKCS1_OAEP_PADDING;
use const PHP_URL_SCHEME;

use function array_column;
use function array_combine;
use function array_keys;
use function base64_decode;
use function base64_encode;
use function gettype;
use function is_int;
use function is_string;
use function ltrim;
use function openssl_pkey_get_private;
use function openssl_pkey_get_public;
use function openssl_private_decrypt;
use function openssl_public_encrypt;
use function openssl_sign;
use function openssl_verify;
use function pack;
use function parse_url;
use function preg_match;
use function sprintf;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function wordwrap;

use UnexpectedValueException;

/**
 * RSA `PKEY` loader and encrypt/decrypt/sign/verify methods.
 */
class Rsa
{
    /** @var string - Type string of the asymmetric key */
    public const KEY_TYPE_PUBLIC = 'public';
    /** @var string - Type string of the asymmetric key */
    public const KEY_TYPE_PRIVATE = 'private';

    private const LOCAL_FILE_PROTOCOL = 'file://';
    private const PKEY_PEM_NEEDLE = ' KEY-';
    private const PKEY_PEM_FORMAT = "-----BEGIN %1\$s KEY-----\n%2\$s\n-----END %1\$s KEY-----";
    private const PKEY_PEM_FORMAT_PATTERN = '#-{5}BEGIN ((?:RSA )?(?:PUBLIC|PRIVATE)) KEY-{5}\r?\n([^-]+)\r?\n-{5}END \1 KEY-{5}#';
    private const CHR_CR = "\r";
    private const CHR_LF = "\n";

    /** @var array<string,array{string,string,int}> - Supported loading rules */
    private const RULES = [
        'private.pkcs1' => [self::PKEY_PEM_FORMAT, 'RSA PRIVATE', 16],
        'private.pkcs8' => [self::PKEY_PEM_FORMAT, 'PRIVATE',     16],
        'public.pkcs1'  => [self::PKEY_PEM_FORMAT, 'RSA PUBLIC',  15],
        'public.spki'   => [self::PKEY_PEM_FORMAT, 'PUBLIC',      14],
    ];

    private const ASN1_OID_RSAENCRYPTION = '300d06092a864886f70d0101010500';
    private const ASN1_SEQUENCE = 48;
    private const CHR_NUL = "\0";
    private const CHR_ETX = "\3";

    private static function encodeLength(string $thing): string
    {
        $num = strlen($thing);
        if ($num <= 0x7F) {
            return sprintf('%c', $num);
        }

        $tmp = ltrim(pack('N', $num), self::CHR_NUL);
        return pack('Ca*', strlen($tmp) | 0x80, $tmp);
    }

    public static function pkcs1ToSpki(string $thing): string
    {
        // 将 PKCS#1 公钥内容包裹为 SPKI 结构：算法标识 + BIT STRING
        $raw = self::CHR_NUL . base64_decode($thing);
        $new = pack('H*', self::ASN1_OID_RSAENCRYPTION) . self::CHR_ETX . self::encodeLength($raw) . $raw;

        return base64_encode(pack('Ca*a*', self::ASN1_SEQUENCE, self::encodeLength($new), $new));
    }


    public static function from(
        #[\SensitiveParameter]
        $thing,
        string $type = self::KEY_TYPE_PRIVATE
    )
    {
        // 根据输入类型自动解析来源：文件路径、协议文本、PEM 包裹或 OpenSSL 资源
        $pkey = ($isPublic = $type === static::KEY_TYPE_PUBLIC)
            ? openssl_pkey_get_public(self::parse($thing, $type))
            : openssl_pkey_get_private(self::parse($thing));

        if (false === $pkey) {
            throw new UnexpectedValueException(sprintf(
                'Cannot load %s from(%s), please take care about the \$thing input.',
                $isPublic ? 'publicKey' : 'privateKey',
                gettype($thing)
            ));
        }

        return $pkey;
    }


    private static function parse(
        #[\SensitiveParameter]
        $thing,
        string $type = self::KEY_TYPE_PRIVATE
    )
    {
        $src = $thing;

        // 输入为 PEM 包裹的公钥时，识别并转换为协议文本
        if (is_string($src) && is_int(strpos($src, self::PKEY_PEM_NEEDLE))
            && $type === static::KEY_TYPE_PUBLIC && preg_match(self::PKEY_PEM_FORMAT_PATTERN, $src, $matches)) {
            [, $kind, $base64] = $matches;
            $mapRules = (array)array_combine(array_column(self::RULES, 1/*column*/), array_keys(self::RULES));
            $protocol = $mapRules[$kind] ?? '';
            if ('public.pkcs1' === $protocol) {
                $src = sprintf('%s://%s', $protocol, str_replace([self::CHR_CR, self::CHR_LF], '', $base64));
            }
        }

        // 输入为协议文本时，按规则生成 PEM 包裹内容
        if (is_string($src) && is_bool(strpos($src, self::LOCAL_FILE_PROTOCOL)) && is_int(strpos($src, '://'))) {
            $protocol = parse_url($src, PHP_URL_SCHEME);
            [$format, $kind, $offset] = self::RULES[$protocol] ?? [null, null, null];
            if ($format && $kind && $offset) {
                $src = substr($src, $offset);
                if ('public.pkcs1' === $protocol) {
                    $src = static::pkcs1ToSpki($src);
                    [$format, $kind] = self::RULES['public.spki'];
                }
                return sprintf($format, $kind, wordwrap($src, 64, self::CHR_LF, true));
            }
        }

        return $src;
    }

    /**
     * Check the padding mode whether or nor supported.
     *
     * @param int $padding - The padding mode, only support `OPENSSL_PKCS1_PADDING`, otherwise thrown `\UnexpectedValueException`.
     *
     * @throws UnexpectedValueException
     */
    private static function paddingModeLimitedCheck(int $padding): void
    {
        if ($padding !== OPENSSL_PKCS1_OAEP_PADDING) {
            throw new UnexpectedValueException(sprintf('Here\'s only support the OPENSSL_PKCS1_OAEP_PADDING(4) mode, yours(%d).', $padding));
        }
    }


    /**
     * Verifying the `message` with given `signature` string that uses `OPENSSL_ALGO_SHA256`.
     *
     * @param string $message - Content will be `openssl_verify`.
     * @param string $signature - The base64-encoded ciphertext.
     * @param \OpenSSLAsymmetricKey|\OpenSSLCertificate|resource|string|mixed $publicKey - The public key.
     *
     * @return boolean - True is passed, false is failed.
     * @throws UnexpectedValueException
     */
    public static function verify(string $message, string $signature, $publicKey): bool
    {
        // 使用 RSA-SHA256 验证签名（signature 为 Base64 编码）
        if (($result = openssl_verify($message, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256)) === false) {
            throw new UnexpectedValueException('Verified the input $message failed, please checking your $publicKey whether or nor correct.');
        }

        return $result === 1;
    }

    /**
     * Creates and returns a `base64_encode` string that uses `OPENSSL_ALGO_SHA256`.
     *
     * @param string $message - Content will be `openssl_sign`.
     * @param \OpenSSLAsymmetricKey|\OpenSSLCertificate|resource|string|mixed $privateKey - The private key.
     *
     * @return string - The base64-encoded signature.
     * @throws UnexpectedValueException
     */
    public static function sign(
        string $message,
        #[\SensitiveParameter]
        $privateKey
    ): string
    {
        // 使用 RSA-SHA256 进行签名，并返回 Base64 编码的签名串
        if (!openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new UnexpectedValueException('Signing the input $message failed, please checking your $privateKey whether or nor correct.');
        }

        return base64_encode($signature);
    }

    /**
     * Decrypts base64 encoded string with `$privateKey` in the `$padding`(default is `OPENSSL_PKCS1_OAEP_PADDING`) mode.
     *
     * @param string $ciphertext - Was previously encrypted string using the corresponding public key.
     * @param \OpenSSLAsymmetricKey|\OpenSSLCertificate|resource|string|array{string,string}|mixed $privateKey - The private key.
     * @param int $padding - default is `OPENSSL_PKCS1_OAEP_PADDING`.
     *
     * @return string - The utf-8 plaintext.
     * @throws UnexpectedValueException
     */
    public static function decrypt(
        string $ciphertext,
        #[\SensitiveParameter]
        $privateKey,
        int $padding = OPENSSL_PKCS1_OAEP_PADDING
    ): string
    {
        // 使用私钥解密 Base64 编码密文（默认 OAEP 填充）
        self::paddingModeLimitedCheck($padding);

        if (!openssl_private_decrypt(base64_decode($ciphertext), $decrypted, $privateKey, $padding)) {
            throw new UnexpectedValueException('Decrypting the input $ciphertext failed, please checking your $privateKey whether or nor correct.');
        }

        return $decrypted;
    }
}
