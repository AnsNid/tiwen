<?php

declare(strict_types=1);

/**
 * 后台路由定义
 * 本目录文件由框架 initConfigRoute 启动时自动 require, 无需手动注册
 */

use App\admin\Middleware\AdminAuthMiddleware;
use App\admin\Middleware\CorsMiddleware;
use xphp\Context\ApplicationContext;
use xphp\Contract\ConfigInterface;
use xphp\HttpServer\Router\Router;
use xphp\Session\Middleware\SessionMiddleware;

$adminhostpath = ApplicationContext::getContainer()->get(ConfigInterface::class)->sysget('config.adminhostpath', 'admin');
$adminhostpath = rtrim($adminhostpath, '/');
$adminhostpath = $adminhostpath ?: 'admin';

Router::addGroup('/' . $adminhostpath, function () {

    // 后台首页(SPA 由本地 View/dist 提供)
    Router::addRoute(['GET'], '[/[{file}]]', 'App\admin\Controller\Index@index');

    // SPA 静态构建产物
    Router::addRoute(['GET'], '/assets/{asset:.+}', 'App\admin\Controller\Index@assets');

    Router::addGroup('/api', function () {

        // 登录接口 - 不需要登录验证
        Router::addRoute(['POST', 'OPTIONS', 'GET'], '/login[/{action}]', 'App\admin\Controller\Api@login', [
            'middleware' => [
                SessionMiddleware::class,
            ],
        ]);

        // 统一的API接口处理，绑定中间件
        Router::addGroup('', function () {
            Router::addRoute(['GET', 'POST', 'PUT', 'OPTIONS'], '/{controller}/[{action}]', 'App\admin\Controller\Api@dispatch');
            Router::addRoute(['GET', 'POST', 'PUT', 'OPTIONS'], '/{app}/{controller}/{action}', 'App\admin\Controller\Api@dispatchController');
        }, [
            'middleware' => [
                SessionMiddleware::class,
                AdminAuthMiddleware::class,
            ],
        ]);
    });
}, [
    'middleware' => [
        CorsMiddleware::class,
    ],
]);
