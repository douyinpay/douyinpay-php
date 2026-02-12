<?php declare(strict_types=1);

namespace DouYinPay;

final class Headers
{
    public const Nonce = 'Douyinpay-Nonce';
    public const Serial = 'Douyinpay-Serial';
    public const Signature = 'Douyinpay-Signature';
    public const Timestamp = 'Douyinpay-Timestamp';
    public const SdkAgent = 'Douyinpay-Sdk-Agent';

    //sdk版本号，每次升级都需要更新版本号
    public const SdkAgentVersion = 'noneCli-PHP-v1.0.0-';
}

