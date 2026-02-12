<?php declare(strict_types=1);

namespace DouYinPay;

use DouYinPay\InvalidArgumentException;
use DouYinPay\HttpClient;

final class DouYinBuilder {
    private $config;
    public function __construct(array $config = [])
    {
        $this->verifyParams($config);
        $this->config = $config;
    }

    public function getClient(string $path):HttpClient {
        return new HttpClient($this->config, $path);
    }

    private function verifyParams(array $config = []) {
        if (!(isset($config['mchid']) && is_string($config['mchid'])))
        { throw new Exception\InvalidArgumentException(ERR_MCHID_IS_INVALID); }

        if (!(isset($config['serial']) && is_string($config['serial'])))
        { throw new Exception\InvalidArgumentException(ERR_SERIAL_IS_INVALID); }

        if (!(isset($config['privateKey']) && (is_string($config['privateKey']))))
        { throw new Exception\InvalidArgumentException(ERR_PRIVATEKEY_IS_INVALID); }

        if (!(isset($config['certs']) && is_array($config['certs']) && count($config['certs'])))
        { throw new Exception\InvalidArgumentException(ERR_CERTS_IS_INVALID); }
    }
}
