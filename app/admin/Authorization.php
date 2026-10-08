<?php

declare(strict_types=1);

namespace App\admin;

use xphp\HttpServer\Contract\RequestInterface;
use Psr\Container\ContainerInterface;
use xphp\Contract\ConfigInterface;
use xphp\HttpServer\Contract\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

use Psr\Log\LoggerInterface;


abstract class Authorization {

    public function __construct(
        public RequestInterface $request,
        public ContainerInterface $container,
        protected ResponseInterface $response,
        protected ConfigInterface $config,
        protected LoggerInterface $logger,
        protected CacheInterface $cache,
    ) {
    }

    /**
     * 获取当前登录的管理员信息
     * 由 AdminAuthMiddleware 解析后注入, 含 uid/username/groupid/founder/role/permissions 等
     */
    public function admin(): ?array {
        return $this->request->getAttribute('admin');
    }
}
