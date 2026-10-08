<?php

declare(strict_types=1);

namespace App\admin\Controller\Admin;

use App\admin\Authorization;
use Exception;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

/**
 * 云平台账号绑定与系统版本更新控制器
 * 开发者账号登录/退出、授权凭证读取、系统内核版本检查与升级;
 * 后台框架 (framework.phar) 的独立更新通道已下线
 */
class Upgrade extends Authorization {
    /**
     * 获取云平台授权凭证信息
     */
    public function get() {

        $info = $this->container->get('cloud')->get();

        return $this->jsonResponse([
            'code' => 200,
            'data' => [
                'raw' => $info,
                'user' => $info['keyUsage'] ?? [],
                'name' => $info['GN'] ?? '',
                'authorizationtime' => $info['privateKeyUsagePeriod'] ?? '',
                'cloudregister' => trim($info['crlDistributionPoints'] ?? 'https://cloud.x-php.com', '/') . '/register?token=' . $this->container->get('cloud')->getDeviceId(),
                'release' => RELEASE,
                'version' => VERSION,
            ],
        ]);
    }

    /**
     *  查询系统更新
     * @return mixed
     */
    public function check() {

        $info = $this->container->get('cloud')->get();
        $user = $info['keyUsage'] ?? [];

        if (empty($user['uid'])) {
            return $this->errorResponse('请先登录云平台');
        }

        try {
            $system = $this->container->get('cloud')->post('/server/check', [
                'json' => [
                    'release' => RELEASE,
                    'version' => VERSION,
                ]
            ])->getBody()->getContents();
            $system = json_decode($system, true);
        } catch (Exception $e) {
            return $this->errorResponse('查询出错: ' . $e->getMessage());
        }

        $upgradelist = $system['data']['list'] ?? [];
        // 第一个值
        $serverVersion = $upgradelist[0] ?? [];

        return $this->jsonResponse([
            'code' => 200,
            'data' => [
                'release' => RELEASE,
                'version' => VERSION,
                'upgrade' => count($upgradelist),
                'upgradelist' => $upgradelist,
                'serverVersion' => $serverVersion,
                'last_updates_release' => $system['data']['last_updates_release'] ?? '',
                'last_updates_version' => $system['data']['last_updates_version'] ?? '',
            ],
        ]);
    }

    /**
     * 更新至对应版本 (下载系统内核并重启服务)
     * @log
     * @return mixed
     */
    public function update() {

        $release = input('release');
        $version = input('version');

        if (empty($release) || empty($version)) {
            return $this->errorResponse('参数传递错误', 201);
        }

        if (version_compare($release, RELEASE, '<=')) {
            return $this->errorResponse('暂不支持降低版本', 201);
        }


        $res = $this->container->get('cloud')->post('/server/getUpgradeUrl', [
            'json' => [
                'release' => $release,
                'version' => $version,
            ]
        ])->getBody()->getContents();
        $res = json_decode($res, true);
        if (isset($res['code']) && $res['code'] == 200) {

            $downloadUrl = $res['data']['downloadUrl'] ?? '';
            if (empty($downloadUrl)) {
                return $this->errorResponse('获取升级链接失败', 201);
            }

            if (!constant('ISXPHPPHAR')) {
                showmsg('非 Phar 环境，无法升级');
            }

            // EXEC_PATH 目录是否可以写入
            if (!is_writable(EXEC_PATH)) {
                showmsg(EXEC_PATH . '目录不可写入，无法升级');
            }

            $this->container->get('cloud')->downloadFile($downloadUrl, EXEC_FILE);
            // 设置权限
            chmod(EXEC_FILE, 0755);
            // 重启服务
            \xphp\Framework\Server::restart();

            return $this->jsonResponse([
                'code' => 200,
                'message' => '升级成功, 刷新页面体验吧, 重启服务中...',
                'data' => [
                    'release' => $release,
                    'version' => $version,
                    'token' => md5_file(EXEC_FILE)
                ]
            ]);
        }
        return $this->errorResponse('获取升级链接失败', 400);
    }

    /**
     * 退出云账号
     * @log
     */
    public function quit() {

        $res = $this->container->get('cloud')->post('/server/quit', [
            'json' => [
                'token' => $this->container->get('cloud')->get('serialNumber')
            ]
        ])->getBody()->getContents();
        $res = json_decode($res, true);

        if (isset($res['code']) && $res['code'] == 200) {
            // 重新获取证书
            $this->container->get('cloud')->reacquire();
            return $this->jsonResponse([
                'code' => 200,
                'message' => '成功退出登录',
                'data' => $res['data'] ?? []
            ]);
        }

        return $this->errorResponse($res['message'] ?? '云平台绑定失败！(-0)');
    }

    /**
     * 登录云平台
     * @log
     */
    public function login() {

        $account = input('account');
        $password = input('password');
        $data = [
            'json' => [
                'account' => $account,
                'password' => $password,
            ]
        ];

        $response = $this->container->get('cloud')->post('/server/login', $data);
        $res = json_decode($response->getBody()->getContents(), true);

        if (isset($res['code']) && $res['code'] == 200) {
            // 重新获取证书
            $this->container->get('cloud')->reacquire();
            $info = $this->container->get('cloud')->get();
            return $this->jsonResponse([
                'code' => 200,
                'message' => '恭喜您，已成功绑定云平台账号',
                'data' => [
                    'user' => $info['keyUsage'] ?? [],
                ],
            ]);
        }
        return $this->errorResponse($res['message'] ?? '云平台绑定失败！(-0)');
    }

    /**
     * 创建JSON响应
     */
    protected function jsonResponse(array $data, int $status = 200): PsrResponseInterface {
        return $this->response->json($data)->withStatus($status);
    }

    /**
     * 创建错误响应
     */
    protected function errorResponse(string $message, int $status = 200): PsrResponseInterface {
        return $this->jsonResponse([
            'code' => 201,
            'message' => $message,
        ], $status);
    }
}
