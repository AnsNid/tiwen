<?php

declare(strict_types=1);


namespace App\queue;

use xphp\Context\ApplicationContext;
use xphp\Contract\CompressInterface;
use xphp\Contract\UnCompressInterface;

class AnnotationJob extends Job {

    public string $class;

    public string $method;

    public array $params = [];

    public function __construct(string $class, string $method, array $params, int $maxAttempts = 0) {
        $this->class = $class;
        $this->method = $method;
        $this->maxAttempts = $maxAttempts;
        foreach ($params as $key => $value) {
            if ($value instanceof CompressInterface) {
                $value = $value->compress();
            }
            $this->params[$key] = $value;
        }
    }

    public function handle() {

        $container = ApplicationContext::getContainer();

        $class = $container->get($this->class);

        $params = [];
        foreach ($this->params as $key => $value) {
            if ($value instanceof UnCompressInterface) {
                $value = $value->uncompress();
            }
            $params[$key] = $value;
        }

        $container->get(Environment::class)->setAsyncQueue(true);

        $class->{$this->method}(...$params);
    }
}
