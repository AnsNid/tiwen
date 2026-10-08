<?php

namespace App\admin\Controller\Admin;

use App\admin\Authorization;

/**
 * 缓存
 */
class Cache extends Authorization {

    /**
     * 获取配置
     */
    public function get() {
        $list = [
            [
                'type' => 'template',
                'name' => '模板缓存',
                'description' => '清理系统模板编译后的缓存文件',
                'icon' => 'el-icon-Postcard',
                'url' => 'cache/template',
            ],
            [
                'type' => 'cache',
                // 首字母大写
                'name' => '缓存',
                'description' => '清理系统缓存文件',
                'icon' => 'el-icon-Files',
                'url' => 'cache/cache',
            ],
            [
                'type' => 'remoteframework',
                'name' => '框架缓存',
                'description' => '清理系统框架缓存文件',
                'icon' => 'el-icon-ScaleToOriginal',
                'url' => 'cache/remoteframework',
            ],
            [
                'type' => 'log',
                'name' => '日志缓存',
                'description' => '清理系统日志缓存文件',
                'icon' => 'el-icon-Tickets',
                'url' => 'cache/log',
            ],
            [
                'type' => 'temp',
                'name' => '临时缓存',
                'description' => '清理系统临时缓存文件, 清理后可重新生成; 请不要在高峰时间进行清理;',
                'icon' => 'el-icon-Delete',
                'url' => 'cache/temp',
            ]
        ];

        if (app('?upload')) {
            $list[] = [
                'type' => 'upload',
                'name' => '上传缓存',
                'description' => '清理上传文件时留下的缓存临时文件',
                'icon' => 'el-icon-Upload',
                'url' => 'cache/upload',
            ];
        }

        show_json([
            'code' => 200,
            'data' => [
                'description' => '更新缓存的时候，可能让服务器负载升高，请尽量避开会员访问的高峰时间。',
                'list' => $list
            ]
        ]);
    }

    /**
     * 清理上传缓存
     * @log
     */
    public function upload() {
        try {
            // 清理上传临时文件目录
            $uploadTempPath = data_path('upload/temp');
            if (is_dir($uploadTempPath)) {
                $this->clearDirectory($uploadTempPath);
            }

            // 清理系统临时目录中的上传文件
            $sysTempPath = sys_get_temp_dir() . '/xphp_upload';
            if (is_dir($sysTempPath)) {
                $this->clearDirectory($sysTempPath);
            }

            show_json([
                'code' => 200,
                'message' => '上传缓存清理成功'
            ]);
        } catch (\Exception $e) {
            show_json([
                'code' => 500,
                'message' => '上传缓存清理失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 清理临时缓存
     * @log
     */
    public function temp() {
        try {
            // 清理运行时缓存目录
            $runtimePath = data_path('runtime');
            if (is_dir($runtimePath)) {
                $this->clearDirectory($runtimePath, ['logs']);
            }

            // 清理临时文件目录
            $tempPath = data_path('temp');
            if (is_dir($tempPath)) {
                $this->clearDirectory($tempPath);
            }

            // 清理缓存目录
            $cachePath = data_path('cache');
            if (is_dir($cachePath)) {
                $this->clearDirectory($cachePath);
            }

            show_json([
                'code' => 200,
                'message' => '临时缓存清理成功'
            ]);
        } catch (\Exception $e) {
            show_json([
                'code' => 500,
                'message' => '临时缓存清理失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 清理日志缓存
     * @log
     */
    public function log() {
        try {
            $path = data_path('logs');

            if (is_dir($path)) {
                // 清理日志文件，但保留目录结构
                $this->clearLogFiles($path);
            }

            show_json([
                'code' => 200,
                'message' => '日志缓存清理成功'
            ]);
        } catch (\Exception $e) {
            show_json([
                'code' => 500,
                'message' => '日志缓存清理失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 清理框架缓存
     * @log
     */
    public function remoteframework() {
        try {
            // 清理配置缓存
            $configPath = data_path('config');
            if (is_dir($configPath)) {
                $this->clearDirectory($configPath);
            }

            // 清理代理缓存
            $proxyPath = data_path('proxy');
            if (is_dir($proxyPath)) {
                $this->clearDirectory($proxyPath);
            }

            // 清理注解缓存
            $annotationPath = data_path('annotation');
            if (is_dir($annotationPath)) {
                $this->clearDirectory($annotationPath);
            }

            // 清理容器缓存
            $containerPath = data_path('container');
            if (is_dir($containerPath)) {
                $this->clearDirectory($containerPath);
            }

            show_json([
                'code' => 200,
                'message' => '框架缓存清理成功'
            ]);
        } catch (\Exception $e) {
            show_json([
                'code' => 500,
                'message' => '框架缓存清理失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 清理缓存
     * @log
     */
    public function cache() {
        // 清理缓存
        $this->container->get('cache')->clear();
        show_json([
            'code' => 200,
            'message' => '缓存清理成功'
        ]);
    }

    /**
     * 清理模板缓存
     * @log
     */
    public function template() {
        try {
            // 得到模板缓存目录
            $templateCachePath = data_path('view');
            if (is_dir($templateCachePath)) {
                $this->clearDirectory($templateCachePath);
            }

            // 清理Twig缓存（如果使用Twig）
            $twigCachePath = data_path('twig');
            if (is_dir($twigCachePath)) {
                $this->clearDirectory($twigCachePath);
            }

            show_json([
                'code' => 200,
                'message' => '模板缓存清理成功'
            ]);
        } catch (\Exception $e) {
            show_json([
                'code' => 500,
                'message' => '模板缓存清理失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 清理所有缓存
     * @log
     */
    public function clear() {
        try {
            // 清理应用缓存
            $this->container->get('cache')->clear();

            // 清理各种文件缓存
            $cachePaths = [
                data_path('runtime'),
                data_path('temp'),
                data_path('cache'),
                data_path('view'),
                data_path('twig'),
                data_path('config'),
                data_path('proxy'),
                data_path('annotation'),
                data_path('container')
            ];

            foreach ($cachePaths as $path) {
                if (is_dir($path)) {
                    if (strpos($path, 'runtime') !== false) {
                        // runtime目录保留logs子目录
                        $this->clearDirectory($path, ['logs']);
                    } else {
                        $this->clearDirectory($path);
                    }
                }
            }

            // 清理上传临时文件
            $uploadTempPath = data_path('upload/temp');
            if (is_dir($uploadTempPath)) {
                $this->clearDirectory($uploadTempPath);
            }

            show_json([
                'code' => 200,
                'message' => '所有缓存清理成功'
            ]);
        } catch (\Exception $e) {
            show_json([
                'code' => 500,
                'message' => '缓存清理失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 清理目录中的文件和子目录
     * @param string $dir 目录路径
     * @param array $exclude 排除的目录名
     */
    private function clearDirectory($dir, $exclude = []) {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (in_array($file, $exclude)) {
                continue;
            }

            $filePath = $dir . DIRECTORY_SEPARATOR . $file;

            if (is_dir($filePath)) {
                $this->clearDirectory($filePath);
                @rmdir($filePath);
            } else {
                @unlink($filePath);
            }
        }
    }

    /**
     * 清理日志文件
     * @param string $dir 日志目录路径
     */
    private function clearLogFiles($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $dir . DIRECTORY_SEPARATOR . $file;

            if (is_dir($filePath)) {
                $this->clearLogFiles($filePath);
            } else {
                // 只删除日志文件，保留.gitkeep等文件
                if (preg_match('/\.(log|txt)$/i', $file)) {
                    @unlink($filePath);
                }
            }
        }
    }
}
