<?php

declare(strict_types=1);

namespace App\admin\Service;

use xphp\HttpServer\Contract\RequestInterface;
use Psr\Container\ContainerInterface;
use xphp\Contract\ConfigInterface;
use App\admin\Annotation\SkipAuth;
use App\admin\lib\ParseComment;

/**
 * 后台统一分发服务
 *
 * 承接所有应用的后台接口调用: 应用校验 → 后台开放校验(admin_enable) → 权限匹配 →
 * 转发到目标应用 Controller\Admin 控制器, 附带 @log 注释解析与访问日志
 */
class DispatchService {

    /**
     * 注释解析缓存
     * 格式：['cache_key' => parsed_comment_array]
     * @var array
     */
    private static array $commentCache = [];

    public function __construct(
        private ContainerInterface $container,
        private RequestInterface $request,
        private ConfigInterface $config,
    ) {
    }

    /**
     * 登录代理
     */
    public function login(string $action = 'index') {
        return $this->forwardToAppOrController('admin', 'Login', $action);
    }

    /**
     * 统一分发处理器 - 后台接口
     * 路由格式: /admin/api/{app}/{action}
     * 例如: /admin/api/avatar/upload
     */
    public function dispatch(string $controller, string $action = 'index') {

        if (strtolower($controller) == 'login') {
            return $this->forwardToAppOrController('admin', 'Login', $action);
        }
        return $this->handle('admin', $controller, $action);
    }

    /**
     * 统一分发处理器 - Controller控制器
     * 路由格式: /admin/api/{app}/{controller}/{action}
     * 例如: /admin/api/avatar/Admin/upload
     */
    public function dispatchController(string $app, string $controller, string $action) {
        return $this->handle($app, $controller, $action);
    }

    /**
     * 处理请求的核心方法
     *
     * 处理流程：
     * 1. 应用有效性校验
     * 2. 后台开放状态校验
     * 3. 权限验证
     * 4. 注释解析和缓存
     * 5. 日志记录检查
     * 6. 方法调用
     */
    private function handle(string $app, string $controller, string $action) {

        // 验证应用是否存在
        if (!$this->isValidApp($app)) {
            return $this->errorResponse('应用不存在', 404, [
                'app' => $app,
            ]);
        }

        // 应用是否开放后台访问(admin_enable)
        if (!$this->container->get(RegistryService::class)->dispatchAllowed($app)) {
            return $this->errorResponse('该应用未开放后台管理', 403, [
                'app' => $app,
            ]);
        }

        // SkipAuth 标记的方法免登录 (AdminAuthMiddleware 放行), 同样不参与细分权限校验:
        // 匿名请求没有权限上下文, 继续走 checkPermission 必然 403
        if ($this->isSkipAuthTarget($app, $controller, $action)) {
            try {
                return $this->forwardToAppOrController($app, ucfirst($controller), $action);
            } catch (\Throwable $e) {
                isJsonException($e);
                return $this->errorResponse('接口调用失败: ' . $e->getMessage(), 500, [
                    'params' => $this->getRequestParams(),
                    'file' => str_replace(BASE_PATH, '', $e->getFile()),
                    'line' => $e->getLine(),
                    'type' => get_class($e),
                ]);
            }
        }

        // 检查权限
        if (!$this->checkPermission($app, $controller, $action)) {
            return $this->errorResponse('权限不足', 403, [
                'app' => $app,
                'controller' => $controller,
                'action' => $action,
            ]);
        }

        try {
            // 分发到对应的应用接口或控制器
            return $this->forwardToAppOrController($app, ucfirst($controller), $action);
        } catch (\Throwable $e) {
            isJsonException($e);
            // 记录异常日志
            return $this->errorResponse('接口调用失败: ' . $e->getMessage(), 500, [
                'params' => $this->getRequestParams(),
                'file' => str_replace(BASE_PATH, '', $e->getFile()),
                'line' => $e->getLine(),
                'type' => get_class($e),
            ]);
        }
    }

    /**
     * 验证应用是否有效
     */
    private function isValidApp(string $app): bool {
        return app('?' . $app, true) && is_dir(app_path($app));
    }

    /**
     * 目标方法是否标记 SkipAuth (免登录入口)
     * 与 AdminAuthMiddleware 的注解放行保持一致, 使其同时豁免细分权限校验
     */
    private function isSkipAuthTarget(string $app, string $controller, string $action): bool {
        static $cache = [];
        $class = "App\\{$app}\\Controller\\Admin\\" . ucfirst($controller);
        $key = $class . '::' . $action;
        if (!array_key_exists($key, $cache)) {
            $skip = false;
            if (class_exists($class) && method_exists($class, $action)) {
                try {
                    $reflectionClass = new \ReflectionClass($class);
                    $skip = !empty($reflectionClass->getAttributes(SkipAuth::class))
                        || !empty($reflectionClass->getMethod($action)->getAttributes(SkipAuth::class));
                } catch (\Throwable) {
                    $skip = false;
                }
            }
            $cache[$key] = $skip;
        }
        return $cache[$key];
    }

    /**
     * 检查权限
     * enforce_permissions 关闭时登录即可访问; 开启后按权限键严格匹配
     * 匹配规则: app.controller.action / app.action / app.* / *(超管)
     */
    private function checkPermission(string $app, string $controller, string $action): bool {

        // 安装控制器豁免: 安装时尚无管理员会话, 与 AdminAuthMiddleware 的 install 跳过保持一致
        if (strtolower($controller) === 'install') {
            return true;
        }

        if (!$this->config->get('admin@config.enforce_permissions', false)) {
            return true;
        }

        // 获取当前管理员信息
        $admin = $this->request->getAttribute('admin');
        if (!$admin) {
            return false;
        }

        // 超级管理员拥有所有权限
        $permissions = (array) ($admin['permissions'] ?? []);
        if (($admin['founder'] ?? false) || in_array('*', $permissions, true)) {
            return true;
        }

        // 未配置任何权限键时视为未启用细分授权, 放行以保持向后兼容
        if (empty($permissions)) {
            return true;
        }

        // 检查具体权限
        $permissionKeys = [
            $app . '.' . ucfirst($controller) . '.' . $action, // app.controller.action
            $app . '.' . $action,                              // app.action (兼容API格式)
            $app . '.*'                                        // app.*  (应用全权限)
        ];

        foreach ($permissionKeys as $key) {
            if (in_array($key, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 转发到对应应用的接口或控制器
     */
    private function forwardToAppOrController(string $app, string $controller, string $action) {
        // 构建类名
        // Controller控制器类
        $className = "App\\{$app}\\Controller\\Admin\\{$controller}";

        // 检查类是否存在
        if (!class_exists($className)) {
            throw new \Exception("应用 {$app} 的 {$controller} 类不存在");
        }

        // 兼容中划线(kebab-case)与下划线命名方法自动转换为小驼峰(camelCase)或下划线
        if (!method_exists($className, $action)) {
            $camelAction = lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $action))));
            if (method_exists($className, $camelAction)) {
                $action = $camelAction;
            } else {
                $snakeAction = str_replace('-', '_', $action);
                if (method_exists($className, $snakeAction)) {
                    $action = $snakeAction;
                }
            }
        }

        // 解析注释并检查日志需求
        [$parsedClassComment, $parsedActionComment] = $this->parseCommentsWithCache($className, $action);

        // 检查是否需要记录访问日志
        if ($this->shouldLogAccess($parsedClassComment, $parsedActionComment)) {
            $this->logAccess($app, $controller, $action, $parsedClassComment, $parsedActionComment);
        }

        // 实例化并调用方法
        return $this->invokeMethod($className, $action);
    }

    /**
     * 获取请求参数
     */
    private function getRequestParams(): array {
        $method = strtoupper($this->request->getMethod());

        if ($method === 'GET') {
            return $this->request->getQueryParams();
        }

        if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
            $contentType = $this->request->getHeaderLine('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $body = $this->request->getBody()->getContents();
                return json_decode($body, true) ?: [];
            }
            return $this->request->getParsedBody() ?: [];
        }

        return [];
    }

    /**
     * 解析类和方法注释（带缓存）
     */
    private function parseCommentsWithCache(string $className, string $action): array {

        $classCacheKey = 'class_' . md5($className);
        $methodCacheKey = 'method_' . md5($className . '::' . $action);

        // 获取类注释
        if (!isset(self::$commentCache[$classCacheKey])) {
            $reflectionClass = new \ReflectionClass($className);
            $docComment = $reflectionClass->getDocComment();
            self::$commentCache[$classCacheKey] = $docComment ? $this->parseCommentToArray($docComment) : [];
        }

        // 获取方法注释
        if (!isset(self::$commentCache[$methodCacheKey])) {
            $reflectionMethod = new \ReflectionMethod($className, $action);
            $actionDocComment = $reflectionMethod->getDocComment();
            self::$commentCache[$methodCacheKey] = $actionDocComment ? $this->parseCommentToArray($actionDocComment) : [];
        }

        return [self::$commentCache[$classCacheKey], self::$commentCache[$methodCacheKey]];
    }

    /**
     * 检查是否需要记录访问日志
     */
    private function shouldLogAccess(array $classComment, array $actionComment): bool {
        return isset($actionComment['log']) || isset($classComment['log']);
    }

    /**
     * 实例化类并调用方法
     */
    private function invokeMethod(string $className, string $action) {
        $instance = $this->container->get($className);
        if (!method_exists($instance, $action)) {
            throw new \Exception("方法 {$action} 不存在");
        }
        return $instance->$action();
    }

    /**
     * 记录访问日志
     */
    private function logAccess(string $app, string $controller, string $action, array $classComment, array $actionComment): void {

        $admin = $this->request->getAttribute('admin');
        $logData = [
            'uid' => $admin['uid'] ?? 0,
            'username' => $admin['username'] ?? 'unknown',
            'route' => $app . '/' . $controller . '/' . $action,
            'description' => join('->', [$classComment['name'], $actionComment['name'] ?? '未知操作']),
            'ip' => ip(),
            'user_agent' => $this->request->getHeaderLine('User-Agent'),
            'request_data' => $this->getRequestParams(),
        ];
        $this->container->get(\xphp\Logger\LoggerFactory::class)->get('admin', 'admin')->info($logData['description'], $logData);
    }

    /**
     * 解析注释为数组
     */
    private function parseCommentToArray(string $docComment): array {
        if (empty($docComment)) {
            return [];
        }
        return (new ParseComment())->parseCommentToArray($docComment);
    }

    /**
     * 返回错误响应
     */
    private function errorResponse(string $message, int $code = 400, array $data = []) {
        $this->container->get(\xphp\Logger\LoggerFactory::class)->get('admin', 'admin')->error($message, $data);
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data
        ], $code, [], [
            'json_encode_param' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ]);
    }
}
