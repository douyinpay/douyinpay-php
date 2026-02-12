<?php declare(strict_types=1);

namespace DouYinPay;

use function preg_replace_callback_array;
use function strtolower;
use function implode;
use function array_filter;

use ArrayIterator;

/**
 * Chainable the client for sending HTTP requests.
 */
final class Builder
{

    public static function factory(array $config = []): BuilderChainable
    {
        return new class([], new ClientDecorator($config)) extends ArrayIterator implements BuilderChainable
        {
            use BuilderTrait;

            /**
             * Compose the chainable `ClientDecorator` instance, most starter with the tree root point
             * @param string[] $input
             * @param ?ClientDecoratorInterface $instance
             */
            public function __construct(array $input = [], ?ClientDecoratorInterface $instance = null) {
                parent::__construct($input, self::STD_PROP_LIST | self::ARRAY_AS_PROPS);

                $this->setDriver($instance);
            }

            /**
             * @var ClientDecoratorInterface $driver - The `ClientDecorator` instance
             */
            protected $driver;

            /**
             * `$driver` setter
             * @param ClientDecoratorInterface $instance - The `ClientDecorator` instance
             */
            public function setDriver(ClientDecoratorInterface &$instance): BuilderChainable
            {
                $this->driver = $instance;

                return $this;
            }

            /**
             * @inheritDoc
             */
            public function getDriver(): ClientDecoratorInterface
            {
                return $this->driver;
            }

            /**
             * Normalize the `$thing` by the rules: `PascalCase` -> `camelCase`
             *                                    & `camelCase` -> `kebab-case`
             *                                    & `_placeholder_` -> `{placeholder}`
             *
             * @param string $thing - The string waiting for normalization
             *
             * @return string
             */
            protected function normalize(string $thing = ''): string
            {
                // 当传入整段路径（包含斜杠）时，不进行大小写改写
                if (false !== strpos($thing, '/')) {
                    return $thing;
                }
                // PascalCase -> camelCase；camelCase -> kebab-case；占位符 _name_ -> {name}
                return preg_replace_callback_array([
                    '#^[A-Z]#'   => static function(array $piece): string { return strtolower($piece[0]); },
                    '#[A-Z]#'    => static function(array $piece): string { return '-' . strtolower($piece[0]); },
                    '#^_(.*)_$#' => static function(array $piece): string { return '{' . $piece[1] . '}'; },
                ], $thing) ?? $thing;
            }

            /**
             * URI pathname
             *
             * @param string $seperator - The URI seperator, default is slash(`/`) character
             *
             * @return string - The URI string
             */
            protected function pathname(string $seperator = '/'): string
            {
                // 将链路中的段落合并为路径
                return implode($seperator, $this->simplized());
            }

            /**
             * Only retrieve a copy array of the URI segments
             *
             * @return string[] - The URI segments array
             */
            protected function simplized(): array
            {
                // 过滤掉链式节点，仅保留字符串段落
                return array_filter($this->getArrayCopy(), static function($v) { return !($v instanceof BuilderChainable); });
            }

            /**
             * @inheritDoc
             */
            public function offsetGet($key): BuilderChainable
            {
                if (!$this->offsetExists($key)) {
                    $indices   = $this->simplized();
                    $indices[] = $this->normalize($key);
                    $this->offsetSet($key, new self($indices, $this->getDriver()));
                }

                return parent::offsetGet($key);
            }

            /**
             * @inheritDoc
             */
            public function chain(string $segment): BuilderChainable
            {
                // 追加路径段或整段路径（包含斜杠时原样保留）
                return $this->offsetGet($segment);
            }
        };
    }

    private function __construct()
    {
        // cannot be instantiated
    }
}
