<?php

namespace App\admin;

use Exception;
use xphp\Context\ApplicationContext;

/**
 * 应用插件
 * */
class Application {

    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct() {
        $this->container = ApplicationContext::getContainer();
    }

    /**
     * 验证require
     * @param array $require
     */
    public function checkrequire($require = []) {

        if (!is_array($require) || empty($require)) {
            return;
        }

        // 需要的 应用
        $requireApp = [];
        foreach ($require as $package => $constraint) {
            $operator = '>=';
            $version = $constraint;
            if (strpos($package, '@') !== false) {
                $app = trim($package, '@');
                if (!app('?' . $app, true)) {
                    $requireApp[$app] = $constraint;
                    continue;
                }
                $appConfig = $this->config($app);
                if (!version_compare($appConfig['version'], $version, $operator)) {
                    throw new Exception(ucfirst($app) . ' 版本不匹配,需要 ' . $constraint);
                }
            }
        }


        if ($requireApp) {
            $cloudlist = $this->cloudlist();
            $requireApplist = [];
            $weight = 0;
            foreach ($requireApp as $app => $constraint) {
                if (!isset($cloudlist[$app])) {
                    show_json([
                        'code' => 201,
                        'message' => '没有找到所需要的应用【' . $app . $constraint . '】,请手动安装',
                    ]);
                }

                if (isset($cloudlist[$app]['require']) && $cloudlist[$app]['require']) {
                    foreach ($cloudlist[$app]['require'] as $package => $constraint) {
                        if (strpos($package, '@') !== false) {
                            $apps = trim($package, '@');
                            if (!app('?' . $apps, true)) {
                                $requireApplist[$apps] = array_merge($cloudlist[$apps], [
                                    'loading' => false,
                                    'installed' => app('?' . $apps, true),
                                    // 权重最高
                                    'weight' => 100,
                                ]);
                            }
                        }
                    }
                }

                if (isset($requireApplist[$app])) {
                    continue;
                }

                $requireApplist[$app] = array_merge($cloudlist[$app] ?: [], [
                    'loading' => false,
                    'installed' => app('?' . $app, true),
                    'weight' => $weight,
                ]);
                $weight++;
            }
            $requireApplist = $this->container->get('array')->orderby(array_values($requireApplist), 'weight', SORT_DESC);
            show_json([
                'code' => 501,
                'message' => '需要先安装以下应用【' . implode("】【", array_column($requireApplist, 'name')) . '】',
                'data' => [
                    'requireApplist' => $requireApplist,
                ]
            ]);
        }
    }

    /**
     * 获取当前版本的远程应用插件
     */
    public function cloudlist() {

        $list = $this->container->get('cloud')->post('/server/applicationList')->getBody()->getContents();
        $list = json_decode($list, true);
        $data = [];
        foreach ($list['data'] as $value) {
            $value['app'] = $value['app'] ?? $value['identifier'];
            $data[$value['app']] = $value;
        }
        return $data;
    }

    /**
     * 本地应用列表
     */
    public function locallist() {

        $appPath = $this->container->get('app')->appPath();
        $appPharPath = $this->container->get('app')->appPharPath();

        $files = glob($appPath . '*');
        $files = array_map(function ($value) {
            return pathinfo($value, PATHINFO_BASENAME);
        }, $files);

        $pharFiles = glob($appPharPath . '*.phar');

        $pharFiles = array_map(function ($value) {
            return pathinfo($value, PATHINFO_FILENAME);
        }, $pharFiles);

        $apps = array_values(array_unique(array_merge($files, $pharFiles)));

        $data = [];
        foreach ($apps as $app) {
            $config = $this->config($app);
            if ($config) {
                $data[$app] = array_merge($config, ['app' => $app, 'islocal' => true]);
            }
        }
        return $data;
    }

    /**
     * 随机字符串背景颜色
     */
    private function color($string = null) {
        $color = [
            '#FFCC99',
            '#66CCCC',
            '#FF6666',
            '#666699',
            '#0099CC',
            '#009999',
            '#CC6600',
            '#003366',
            '#339999',
            '#006633',
        ];
        $key = substr(ord($string), -1, 1);
        return $color[$key] ?? '#666666';
    }

    /**
     * 无头像时
     */
    private function noavatar($string = null) {
        $string = strtoupper(mb_substr($string, 0, 1, 'utf-8'));
        $data = base64_encode('<svg xmlns="http://www.w3.org/2000/svg" version="1.1" height="200" width="200"><rect fill="' . $this->color($string) . '" x="0" y="0" width="200" height="200"></rect><text x="100" y="100" font-size="80" fill="#ffffff" text-anchor="middle" alignment-baseline="central">' . $string . '</text></svg>');
        return 'data:image/svg+xml;base64,' . $data;
    }

    /**
     * 读取配置
     */
    public function config($app = null) {

        $appInstallPath = $this->container->get('app')->getAppPath($app, 'Install');
        if (!is_dir($appInstallPath)) {
            return false;
        }
        $config = cache_read('config', $appInstallPath);
        $config['icon'] = $config['icon'] ?: (
            is_readable($appInstallPath . 'logo.png') ?
            'data:image/png;base64,' . base64_encode(file_get_contents($appInstallPath . 'logo.png')) :
            $this->noavatar($config['name'] ?: $app)
        );
        return $config;
    }

    /**
     * 应用安装目录
     */
    public function realpath($app = null) {
        return $this->container->get('app')->getAppPath($app, 'Install');
    }

    /**
     * 缓存
     */
    public function cache() {
        $name = 'apps';
        $value = m('application')->pluck('status', 'app')->toArray();
        cache_write($name, $value, BASE_PATH . '/config');
        return $this->container->get('config')->set('apps', $value);
    }
}
