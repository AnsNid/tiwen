<?php

declare(strict_types=1);

namespace App\crontab\Strategy;

use Psr\Container\ContainerInterface;

abstract class AbstractStrategy implements StrategyInterface {
    public function __construct(protected ContainerInterface $container) {
    }
}
