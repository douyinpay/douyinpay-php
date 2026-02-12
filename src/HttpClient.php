<?php declare(strict_types=1);
namespace DouYinPay;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\RequestInterface;

use DouYinPay\SignOrVerify;

class HttpClient {
    private  $path;
    private $baseClient;
    private $merchantConfig;
    protected static $defaults = [
        'base_uri' => 'https://api.douyinpay.com',
        'headers' => [
            'Accept' => 'application/json, text/plain, application/x-gzip, application/pdf, image/png, image/*;q=0.5',
            'Content-Type' => 'application/json; charset=utf-8',
        ],
    ];

    public function __construct(array $merchantConfig,  $path){
        $this->path = $path;
        $this->merchantConfig = $merchantConfig;
        $this->baseClient = new Client(static::$defaults);
    }

    public function get(array $options = []) :ResponseInterface{
        $baseRequest = new Request("GET","", [], "");
        return $this->send($baseRequest, $options);
    }

    public function post(array $data, array $options = []) :ResponseInterface{
        $jsonData =json_encode($data);
        $baseRequest = new Request("POST","", [], $jsonData);
        return $this->send($baseRequest, $options);
    }

    private function send(RequestInterface $request, array $options = []):ResponseInterface {
        $uri = new Uri(static::$defaults["base_uri"] . $this->path);
        $req = $request->withUri($uri);
        $req = SignOrVerify::sign($req, $this->merchantConfig["mchid"], $this->merchantConfig["serial"], $this->merchantConfig["privateKey"]);
        try {
            $resp = $this->baseClient->send($req, $options);
            // 不成功时抛异常
            \DouYinPay\SignOrVerify::verify($req, $resp, $this->merchantConfig["certs"]);
            return $resp;
        } catch (GuzzleException $e) {
            return $this->exceptionCatch($e);
        }
    }

    private function exceptionCatch(GuzzleException $e):ResponseInterface {
        if ($e instanceof ClientException || $e instanceof ServerException  && $e->hasResponse()) {
            return $e->getResponse();
        }
        // 需要处理一下非上述两种异常的处理方案
        return new Response(500, [], '');
    }
}
