<?php declare(strict_types=1);

namespace DouYinPay;

use DouYinPay\Crypto\AesInterface;
use function in_array;
use function openssl_get_cipher_methods;
use function openssl_encrypt;
use function base64_encode;
use function base64_decode;
use function substr;
use function strlen;
use function openssl_decrypt;

use const OPENSSL_RAW_DATA;

use RuntimeException;
use UnexpectedValueException;

/**
 * Aes encrypt/decrypt using `aes-256-gcm` algorithm with additional authenticated data(`aad`).
 */
class AesUtil
{

    public const BLOCK_SIZE = 16;
    public const ALGO_AES_256_GCM = "aes-256-gcm";

    public static function encrypt(string $plaintext, string $key, string $nonce = '', string $aad = ''): string
    {

        $ciphertext = openssl_encrypt($plaintext, static::ALGO_AES_256_GCM, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, static::BLOCK_SIZE);

        if (false === $ciphertext) {
            throw new UnexpectedValueException('Encrypting the input $plaintext failed, please checking your $key and $nonce whether or nor correct.');
        }

        return base64_encode($ciphertext . $tag);
    }

    public static function decrypt(string $ciphertext, string $key, string $nonce = '', string $aad = ''): string
    {

        $ciphertext = base64_decode($ciphertext);
        $authTag = substr($ciphertext, $tailLength = 0 - static::BLOCK_SIZE);
        $tagLength = strlen($authTag);

        if ($tagLength > static::BLOCK_SIZE || ($tagLength < 12 && $tagLength !== 8 && $tagLength !== 4)) {
            throw new RuntimeException('The inputs `$ciphertext` incomplete, the bytes length must be one of 16, 15, 14, 13, 12, 8 or 4.');
        }

        $plaintext = openssl_decrypt(substr($ciphertext, 0, $tailLength), static::ALGO_AES_256_GCM, $key, OPENSSL_RAW_DATA, $nonce, $authTag, $aad);

        if (false === $plaintext) {
            throw new UnexpectedValueException('Decrypting the input $ciphertext failed, please checking your $key and $nonce whether or nor correct.');
        }

        return $plaintext;
    }
}
