<?php declare(strict_types=1);

namespace DouYinPay;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\ServerException;
use DouYinPay\Headers;

use UnexpectedValueException;

class SignOrVerify {
    public  static function sign(RequestInterface $request, $mchid, $serial, $privateKey) :RequestInterface{
        $nonce = Formatter::nonce();
        $timestamp = (string) Formatter::timestamp();
        $body = HttpUtil::body($request);

        $signature = Crypto\Rsa::sign(Formatter::request($request->getMethod(), $request->getRequestTarget(), $timestamp, $nonce, $body), $privateKey);

        $request = $request->withHeader('Authorization', Formatter::authorization($mchid, $nonce, $signature, $timestamp, $serial));
        return $request->withHeader(Headers::SdkAgent, Headers::SdkAgentVersion.$mchid);
    }

    public static function verify(RequestInterface $request, ResponseInterface $response, array $platformCerts):void {

        if (!($response->hasHeader(Headers::Nonce) && $response->hasHeader(Headers::Serial)
            && $response->hasHeader(Headers::Signature) && $response->hasHeader(Headers::Timestamp))) {
            throw new ServerException(sprintf(
                Exception\DouYinPayException::ERR_RES_HEADERS_INCOMPLETE,
                Headers::Nonce, Headers::Serial, Headers::Signature, Headers::Timestamp
            ), $request, $response);
        }

        [$nonce] = $response->getHeader(Headers::Nonce);
        [$serial] = $response->getHeader(Headers::Serial);
        [$signature] = $response->getHeader(Headers::Signature);
        [$timestamp] = $response->getHeader(Headers::Timestamp);

        if (!array_key_exists($serial,$platformCerts)) {
            throw new ServerException(sprintf(
                Exception\DouYinPayException::ERR_RES_HEADER_PLATFORM_SERIAL,
                $serial, Headers::Serial, implode(',', array_keys($platformCerts))
            ), $request, $response);
        }
         if (!Crypto\Rsa::verify(Formatter::response($timestamp, $nonce, HttpUtil::body($response)), $signature, $platformCerts[$serial])) {
            throw new   ServerException(sprintf(
                Exception\DouYinPayException::ERR_RES_HEADER_SIGNATURE_DIGEST,
                $timestamp, $nonce, $signature, $serial
            ), $request, $response);
        }
    }
}

