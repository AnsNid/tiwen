<?php

declare(strict_types=1);

namespace App\admin\Middleware;

use App\admin\Controller\Api;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use xphp\Contract\ConfigInterface;
use xphp\HttpServer\Contract\ResponseInterface as HttpResponse;
use xphp\HttpServer\Router\Dispatched;

/**
 * 跨域请求处理中间件
 * 专门用于处理需要跨域但不需要认证的接口
 */
class CorsMiddleware extends \xphp\HttpServer\Middleware\CorsMiddleware {

    public function __construct(protected HttpResponse $response, ConfigInterface $config) {
        parent::__construct($response, $config);
    }

    /**
     * 处理请求
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {

        // 处理跨域预检请求
        if ($request->getMethod() === 'OPTIONS') {
            return $this->preflight($request);
        }

        // 如果发现是首次访问, 页面请求进入安装
        // API 请求不拦截: 安装器 SPA 依赖 /{adminhostpath}/api/install/* 完成安装, 拦截会导致安装接口返回 HTML 无法使用
        if (!$this->isApiRequest($request)
            && !file_exists(runtime_path('config') . 'config.php')
            && !file_exists(runtime_path() . 'install.lock')) {
            return $this->install();
        }

        // 处理正常请求并添加跨域头
        return $this->withCustomHeaders($this->withCorsHeaders($handler->handle($request), $request));
    }

    /**
     * 是否后台 API 请求(经 Api 控制器分发的请求), 与 adminhostpath 配置无关
     */
    private function isApiRequest(ServerRequestInterface $request): bool {
        $dispatched = $request->getAttribute(Dispatched::class);
        $callback = $dispatched?->handler?->callback ?? null;
        return is_string($callback) && str_starts_with($callback, Api::class . '@');
    }

    /**
     * 放开所有来源, 保留 Token 请求头
     */
    protected function options(): array {
        return [
            'allow_origins' => '*',
            'allow_headers' => array_merge(parent::DEFAULT_HEADERS, ['Token']),
        ];
    }

    /**
     * 首次运行, 需要安装
     */
    protected function install(): ResponseInterface {
        $html = file_get_contents(app_path('admin', 'View') . 'install.html');
        if ($html === false) {
            return $this->response->raw('install.html not found')
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withStatus(200);
        }
        return $this->response->raw($html)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus(200);
    }
}
