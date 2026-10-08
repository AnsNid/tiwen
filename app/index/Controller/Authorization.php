<?php

declare(strict_types=1);

namespace App\index\Controller;

use xphp\HttpServer\Contract\RequestInterface;
use xphp\HttpServer\Contract\ResponseInterface;
use Psr\Container\ContainerInterface;
use xphp\Contract\SessionInterface;

abstract class Authorization {

    public function __construct(
        protected ContainerInterface $container,
        protected RequestInterface $request,
        protected ResponseInterface $response,
        protected SessionInterface $session,
    ) {
    }
}
