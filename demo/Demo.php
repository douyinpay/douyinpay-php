<?php

namespace DouYinPay\Demo;


require_once __DIR__ . '/../vendor/autoload.php';

use DouYinPay\DouYinBuilder;
use GuzzleHttp\Psr7\Uri;

use Exception;
const merchantID = "yourMerchantID"; // 此处使用实际的商户ID替换
const appID = "yourMerchantAPPID"; // 此处使用商户实际的AppID替换
const merchantSerialNo = "yourMerchantSerialNo"; // 此处使用商户实际的证书序列号替换
const merchantPrivateKey = "yourMerchantPrivateKey"; // 此处使用商户实际的私钥替换
const douyinpaySerialNo = "douyinpaySerialNo"; // 此处使用抖音平台公钥证书序列号替换
const douyinpayPublicKey = "douyinpayPublicKey"; // 此处使用抖音平台公钥替换

function query(DouYinBuilder $builder, string $outTradeNo): void
{
    try {
        $path = "/v1/trade/transactions/out-trade-no/" . $outTradeNo;
        $uri = new Uri($path);
        $query = "mchid=" . merchantID;
        $uri = $uri->withQuery($query);
        printf("uri info:%s\n", $uri->getPath());

        $response = $builder->getClient($uri->__toString())->get(['debug' => true]);
        echo $response->getStatusCode() . ' ' . $response->getReasonPhrase(), PHP_EOL;
        echo $response->getBody()->getContents(), PHP_EOL;
    } catch (Exception $e) {
        echo $e->getMessage(), PHP_EOL;
    }
}

function pay(DouYinBuilder $builder, string $outTradeNo): void
{
    try {
        $prepayReq = array(
            "mchid" => merchantID,
            "appid" => appID,
            "description" => "抖音支付测试",
            "out_trade_no" => $outTradeNo,
            "time_expire" => date("Y-m-d\TH:i:sP", strtotime("+10 minute", time())),
            "notify_url" => "https://www.mock.douyinpay.com",
            "attach" => "",
            "amount" => array(
                "currency" => "CNY",
                "total" => 100,
            ),
            "ip" => "123.22.21.2",
        );
        // debug 参数控制 http请求进入调试模式
        $response = $builder->getClient("/v1/trade/transactions/app")->post($prepayReq, ["debug" => true]);
        echo $response->getStatusCode() . ' ' . $response->getReasonPhrase(), PHP_EOL;
        echo $response->getBody()->getContents(), PHP_EOL;
    } catch (Exception $e) {
        echo $e->getMessage(), PHP_EOL;
    }
}

function payAndQuery(): void
{
    $config = array(
        "mchid" => merchantID,
        "serial" => merchantSerialNo,
        "privateKey" => merchantPrivateKey,
        "certs" => array(
            douyinpaySerialNo => douyinpayPublicKey,
        )
    );
    $builder = new DouYinBuilder($config);
    $outTradeNo = "OUT" . time();
    // 下单
    pay($builder, $outTradeNo);
    // 利用外部单号查询
    query($builder, $outTradeNo);
}

payAndQuery();


