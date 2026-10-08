<?php

declare(strict_types=1);

namespace App\admin\Controller;

use xphp\HttpMessage\Stream\SwooleStream;
use App\admin\Authorization;

/**
 * 后台入口控制器
 * 服务本应用 View/dist 内构建的 SPA(hash 路由), 不再依赖云端 phar
 */
class Index extends Authorization {
    protected function getAppName(): string {
        return (string) ($this->container->get('config')->sysget('config.site_name', '') ?: $this->container->get('config')->get('app_name', 'X-PHP'));
    }

    /**
     * 动态注入的全局配置(供前端读取)
     */
    protected function getAdminframeworkConfig(): string {
        $config = [
            'APP_NAME' => $this->getAppName(),
            'API_URL' => $this->getApiUrl(),
            'APP_VER' => VERSION . '( ' . RELEASE . ' )',
            'TOKEN_NAME' => $this->container->get('config')->get('session.options.var_session_id', 'token'),
            'SESSIONNAME' => $this->container->get('config')->get('session.options.session_name', 'XPHP_SESSION_ID'),
        ];
        return 'const APP_CONFIG = ' . json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . ';';
    }

    private function getApiUrl(): string {
        $baseUrl = $this->container->get('config')->sysget('config.adminhostdomain', '');
        $adminhostpath = $this->container->get('config')->sysget('config.adminhostpath', 'admin');
        $adminhostpath = rtrim($adminhostpath, '/');
        $adminhostpath = $adminhostpath ?: 'admin';
        return rtrim($baseUrl, '/') . '/' . $adminhostpath . '/api';
    }

    /**
     * 后台入口
     */
    public function index(): \Psr\Http\Message\ResponseInterface {
        $file = (string) $this->request->route('file', '');
        if ($file === 'config.js') {
            return $this->generateConfigResponse();
        }
        if ($file !== '' && str_contains($file, '.')) {
            $root = realpath(app_path('admin', 'View/dist'));
            if ($root && is_file($root . '/' . basename($file))) {
                return $this->serveDistFile(basename($file));
            }

        }
        return $this->serveDistFile('index.html');
    }

    /**
     * 构建产物静态资源 /{adminpath}/assets/*
     */
    public function assets(): \Psr\Http\Message\ResponseInterface {
        $filename = basename((string) $this->request->route('asset', ''));
        if ($filename === '' || !str_contains($filename, '.')) {
            return response('Not Found', ['Content-Type' => 'text/plain'])->withStatus(404);
        }
        return $this->serveDistFile('assets/' . $filename);
    }

    private function serveDistFile(string $relative): \Psr\Http\Message\ResponseInterface {

        $root = realpath(app_path('admin', 'View/dist'));
        $path = $root ? realpath($root . '/' . $relative) : false;
        $filename = basename($relative);

        // 防目录穿越
        if (!$root || !$path || !str_starts_with($path, $root) || !is_file($path)) {
            return response('后台前端构建产物不存在, 请在 app/admin/View 下执行 pnpm install && pnpm build', [
                'Content-Type' => 'text/plain; charset=utf-8',
            ])->withStatus(404);
        }

        $currentPath = explode('/', trim($this->request->path(), '/'))[0] ?: 'admin';

        if ($filename === 'index.html') {
            // 转化内容, 注入全局配置与自定义站点名称
            $content = file_get_contents($path);
            $appName = $this->getAppName();
            if ($appName) {
                $content = preg_replace('/<title>.*?<\/title>/i', '<title>' . htmlspecialchars($appName) . '</title>', $content);
                $content = preg_replace('/<meta\s+name=["\']apple-mobile-web-app-title["\']\s+content=["\'].*?["\']/i', '<meta name="apple-mobile-web-app-title" content="' . htmlspecialchars($appName) . '"', $content);
                $content = preg_replace('/<meta\s+name=["\']application-name["\']\s+content=["\'].*?["\']/i', '<meta name="application-name" content="' . htmlspecialchars($appName) . '"', $content);
                $content = preg_replace('/<div class="preloader-title">.*?<\/div>/i', '<div class="preloader-title">' . htmlspecialchars($appName) . '</div>', $content);
            }
            if ($currentPath) {
                // 替换当前路由
                $content = str_replace('./', '/' . $currentPath . '/', $content);
            }
            return response()->withHeader('content-type', 'text/html; charset=utf-8')->withBody(new SwooleStream($content));
        }

        if ($filename === 'sw.js') {
            $content = file_get_contents($path);
            return response()
                ->withHeader('content-type', 'application/javascript; charset=utf-8')
                ->withHeader('Service-Worker-Allowed', '/' . $currentPath . '/')
                ->withHeader('cache-control', 'no-cache, no-store, must-revalidate')
                ->withBody(new SwooleStream($content));
        }

        if ($filename === 'manifest.json') {
            $content = file_get_contents($path);
            $appName = $this->getAppName();
            if ($appName) {
                $manifest = json_decode($content, true);
                if (is_array($manifest)) {
                    if ($appName) {
                        $manifest['name'] = $appName;
                        $manifest['short_name'] = $appName;
                    }
                    unset($manifest['id']);
                    $content = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                }
            }
            return response()
                ->withHeader('content-type', 'application/manifest+json; charset=utf-8')
                ->withHeader('cache-control', 'no-cache')
                ->withBody(new SwooleStream($content));
        }

        return response()->download($path, $filename, 'inline');
    }

    private function generateConfigResponse(): \Psr\Http\Message\ResponseInterface {
        $jsContent = $this->getAdminframeworkConfig();
        return $this->container->get('response')
            ->withHeader('content-description', 'File Transfer')
            ->withHeader('content-type', 'application/javascript')
            ->withHeader('cache-control', 'no-cache')
            ->withBody(new SwooleStream($jsContent));
    }
}
