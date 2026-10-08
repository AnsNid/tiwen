<?php

declare(strict_types=1);


namespace App\openai;

use Psr\Container\ContainerInterface;

final class ClientFactory {

    public function __invoke(ContainerInterface $container): MultiProviderClient {
        // 返回支持多供应商多模型路由的客户端
        return new MultiProviderClient($container);
    }
}
