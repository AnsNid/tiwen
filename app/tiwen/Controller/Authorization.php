<?php

declare(strict_types=1);

namespace App\tiwen\Controller;

use xphp\HttpServer\Contract\RequestInterface;
use xphp\HttpServer\Contract\ResponseInterface;
use xphp\Contract\SessionInterface;
use Psr\Container\ContainerInterface;

abstract class Authorization {
    public function __construct(protected ContainerInterface $container, protected RequestInterface $request, protected ResponseInterface $response, protected SessionInterface $session) {
    }
}
