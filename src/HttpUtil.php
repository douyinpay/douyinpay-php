<?php declare(strict_types=1);
namespace DouYinPay;

use Psr\Http\Message\MessageInterface;

class HttpUtil{
    public static function body(MessageInterface $message): string
    {
        $stream = $message->getBody();
        $content = (string) $stream;

        $stream->tell() && $stream->rewind();

        return $content;
    }
}
