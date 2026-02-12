<?php declare(strict_types=1);

namespace DouYinPay;

class Formatter
{
    public static function nonce(int $size = 32): string
    {
        if ($size < 1) {
            throw new InvalidArgumentException('Size must be a positive integer.');
        }

        return implode('', array_map(static function(string $c): string {
            return '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'[ord($c) % 62];
        }, str_split(random_bytes($size))));
    }

    public static function timestamp(): int
    {
        return time();
    }

    public static function authorization(string $mchid, string $nonce, string $signature, string $timestamp, string $serial): string
    {
        return sprintf(
            'DouyinPay-RSA mchid="%s",serial_no="%s",timestamp="%s",nonce_str="%s",signature="%s"',
            $mchid, $serial, $timestamp, $nonce, $signature
        );
    }

    public static function request(string $method, string $uri, string $timestamp, string $nonce, string $body = ''): string
    {
        return static::implodeWithLine($method, $uri, $timestamp, $nonce, $body);
    }

    public static function response(string $timestamp, string $nonce, string $body = ''): string
    {
        return static::implodeWithLine($timestamp, $nonce, $body);
    }

    public static function implodeWithLine(...$pieces): string
    {
        return implode("\n", array_merge($pieces, ['']));
    }

}