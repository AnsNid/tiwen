<?php

declare(strict_types=1);

namespace App\admin\Controller;

use xphp\HttpServer\Contract\ResponseInterface;
use App\admin\Authorization;
use App\admin\Service\DispatchService;

/**
 * 后台统一接口控制器
 *
 * 职责仅路由承接: 登录代理与统一分发, 具体分发/权限/日志逻辑见 DispatchService
 *
 * @author System
 * @version 2.1
 */
class Api extends Authorization {

    /**
     * 登录代理
     * @return ResponseInterface
     */
    public function login(string $action = 'index') {
        return $this->dispatchService()->login($action);
    }

    /**
     * 统一分发处理器 - 后台接口
     * 路由格式: /admin/api/{app}/{action}
     * 例如: /admin/api/avatar/upload
     */
    public function dispatch(string $controller, string $action = 'index') {
        return $this->dispatchService()->dispatch($controller, $action);
    }

    /**
     * 统一分发处理器 - Controller控制器
     * 路由格式: /admin/api/{app}/{controller}/{action}
     * 例如: /admin/api/avatar/Admin/upload
     */
    public function dispatchController(string $app, string $controller, string $action) {
        return $this->dispatchService()->dispatchController($app, $controller, $action);
    }

    private function dispatchService(): DispatchService {
        return $this->container->get(DispatchService::class);
    }
}
