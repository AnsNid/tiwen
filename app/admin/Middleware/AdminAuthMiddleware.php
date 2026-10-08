<?php

declare(strict_types=1);

namespace App\admin\Middleware;

use App\admin\Annotation\SkipAuth;
use App\admin\Service\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use xphp\HttpServer\Contract\ResponseInterface as HttpResponse;

/**
 * 后台认证中间件
 * 校验管理员登录态并注入 admin 属性; 接口级权限键匹配由分发器(DispatchService)执行
 * 跨域与 OPTIONS 预检由路由组级 CorsMiddleware 统一处理, 本中间件不再重复处理
 */
class AdminAuthMiddleware implements MiddlewareInterface {
    /**
     * SkipAuth 注解检查缓存(controller::method => 是否跳过)
     * 反射读取注解只在首次执行, 后续请求直接命中缓存
     */
    private static array $skipAuthCache = [];

    public function __construct(protected HttpResponse $response, protected AuthService $auth) {
    }

    /**
     * 处理请求
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {

        // 检查当前方法是否有SkipAuth注解
        if ($this->shouldSkipAuth($request)) {
            return $handler->handle($request);
        }

        // 校验管理员登录态: 解包登录令牌绑定会话(纯 Token, 不依赖 Cookie), 失效即拒绝
        $admin = $this->auth->resolve($this->requestToken($request));
        if (!$admin) {
            return $this->unauthorizedResponse('请先登录管理后台');
        }

        // 保存 uid
        $request = $request->withAttribute('uid', $admin['uid'] ?? 0);
        // 将管理员信息添加到请求属性中
        $request = $request->withAttribute('admin', $admin);
        return $handler->handle($request);
    }

    /**
     * 提取登录令牌: 优先 var_session_id 同名请求头(默认 token), 回退同名查询参数
     */
    private function requestToken(ServerRequestInterface $request): ?string {

        $name = (string) ($this->requestConfigName());
        if ($name === '') {
            return null;
        }

        $token = $request->getHeaderLine($name);
        if ($token !== '') {
            return $this->cleanToken($token);
        }

        // 回退检查 token 头 (兼容 Bearer 或直接传令牌)
        $authHeader = $request->getHeaderLine('x-token');
        if ($authHeader !== '') {
            return $this->cleanToken($authHeader);
        }

        $query = $request->getQueryParams()[$name] ?? null;
        return is_string($query) && $query !== '' ? trim($query) : null;
    }

    private function cleanToken(string $token): string {
        $token = trim($token);
        // 如果包含逗号（如因代理或多重设置导致 authorization 包含逗号拼接的多段值），解析出有效部分
        if (str_contains($token, ',')) {
            $parts = array_map('trim', explode(',', $token));
            foreach ($parts as $part) {
                if (stripos($part, 'Bearer ') === 0) {
                    return trim(substr($part, 7));
                }
                if ($part !== '') {
                    return $part;
                }
            }
        }
        if (stripos($token, 'Bearer ') === 0) {
            return trim(substr($token, 7));
        }
        return $token;
    }

    private function requestConfigName(): string {
        try {
            return (string) xphp('config')->get('session.options.var_session_id', 'token');
        } catch (\Throwable) {
            return 'token';
        }
    }

    /**
     * 检查是否应该跳过认证
     */
    private function shouldSkipAuth(ServerRequestInterface $request): bool {
        try {
            // 获取路由信息
            $route = $request->getAttribute('xphp\HttpServer\Router\Dispatched');
            if (!$route || !isset($route->handler)) {
                return false;
            }

            if (strtolower($route->params['controller'] ?? '') === 'install') {
                return true;
            }

            // 直接绑定的 [class, method] 路由: 反射处理器本身
            $handler = $route->handler->callback;
            if (is_array($handler) && count($handler) === 2) {
                $cacheKey = $handler[0] . '::' . $handler[1];
                if (!array_key_exists($cacheKey, self::$skipAuthCache)) {
                    self::$skipAuthCache[$cacheKey] = $this->resolveSkipAuth($handler[0], $handler[1]);
                }
                if (self::$skipAuthCache[$cacheKey]) {
                    return true;
                }
            }

            // 统一分发路由 (/admin/api/{app}/{controller}/{action}): 处理器固定为分发器
            // (Class@method 字符串回调), 实际目标是分发参数指向的应用控制器方法,
            // SkipAuth 需反射分发目标 (未声明 app 时回退 admin 应用)
            $params = $route->params ?? [];
            if (isset($params['controller'], $params['action'])) {
                $targetClass = 'App\\' . ($params['app'] ?? 'admin') . '\\Controller\\Admin\\'
                    . ucfirst((string) $params['controller']);
                $targetKey = $targetClass . '::' . $params['action'];
                if (!array_key_exists($targetKey, self::$skipAuthCache)) {
                    self::$skipAuthCache[$targetKey] = class_exists($targetClass)
                        && $this->resolveSkipAuth($targetClass, (string) $params['action']);
                }
                if (self::$skipAuthCache[$targetKey]) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable) {
            // 如果检查失败，默认不跳过认证
            return false;
        }
    }

    /**
     * 反射检查控制器/方法上的 SkipAuth 注解
     */
    private function resolveSkipAuth(string $controllerClass, string $method): bool {
        $reflection = new ReflectionClass($controllerClass);
        // 检查控制器是否有 SkipAuth 注解
        if (!empty($reflection->getAttributes(SkipAuth::class))) {
            return true;
        }
        // 检查方法是否有 SkipAuth 注解
        $methodReflection = $reflection->getMethod($method);
        return !empty($methodReflection->getAttributes(SkipAuth::class));
    }

    /**
     * 返回未授权响应
     */
    private function unauthorizedResponse(string $message): ResponseInterface {
        return $this->response->json(['code' => 401, 'message' => $message, 'data' => []])->withStatus(401);
    }
}
