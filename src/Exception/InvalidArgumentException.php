<?php declare(strict_types=1);

namespace DouYinPay\Exception;

use GuzzleHttp\Exception\GuzzleException;

class InvalidArgumentException extends \InvalidArgumentException implements DouYinPayException, GuzzleException
{
}
