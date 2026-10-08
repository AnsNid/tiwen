<?php

namespace App\admin\Controller\Admin;

use App\admin\Annotation\SkipAuth;
use App\admin\Authorization;

/**
 * 系统
 */
class System extends Authorization {
    /**
     * 保存, 个人主题设置
     */
    public function style() {

        $user = $this->request->getAttribute('admin');

        $name = input('name', input('post.name', ''));
        $value = input('value', input('post.value', ''));
        if (empty($name)) {
            $rawBody = (string) $this->request->getBody();
            if ($rawBody) {
                $json = json_decode($rawBody, true);
                if (is_array($json)) {
                    $name = $json['name'] ?? '';
                    $value = $json['value'] ?? '';
                }
            }
        }

        if (empty($name)) {
            show_json([
                'code' => 400,
                'message' => '缺少参数 name',
            ]);
        }

        $userdata = app('user.data')->get($user['uid'], ['style']);
        $data = array_merge($userdata['style'] ?: [], [
            $name => $value
        ]);
        app('user.data')->set($user['uid'], ['style' => $data]);
        show_json([
            'code' => 200,
            'data' => $data
        ]);
    }

    /**
     * 我的系统
     */
    public function index() {

        $user = $this->request->getAttribute('admin');
        $userData = $user['uid'] > 0 ? app('user.data')->get($user['uid'], ['commonlyused', 'widgets', 'style']) : [];

        // 桌面扁平结构: 每个开放后台的应用及其入口(Launchpad/Dock 消费)
        try {
            $apps = $this->container->get(\App\admin\Service\RegistryService::class)->desktopApps();
        } catch (\Throwable) {
            $apps = [];
        }

        show_json([
            'code' => 200,
            'data' => [
                'apps' => $apps,
                'fileupload' => '/filesystem/admin/upload',
                "permissions" => $user['permissions'],
                "dashboardgrid" => [],
                'layout' => $userData['style']['layout'] ?? 'default',
                'layoutTags' => $userData['style']['layoutTags'] ?? false,
                'APP_COLOR' => $userData['style']['APP_COLOR'] ?? false,
                'style' => $userData['style'] ?? [],
                'user' => [
                    'uid' => $user['uid'],
                    'username' => $user['nickname'] ?: $user['username'],
                    'nickname' => $user['nickname'] ?: $user['username'],
                    'email' => $user['email'] ?? '',
                    'avatar' => avatar(($user['uin'] ?? 0) ? $user['uin'] : $user['uid'], 'middle', ($user['uin'] ?? 0) ? 1 : 0),
                    'role' => $user['role'],
                ],
                'appLogo' => '',
                'commonlyused' => isset($userData['commonlyused']) && $userData['commonlyused'] ? $userData['commonlyused'] : [],
                'widgets' => isset($userData['widgets']) && $userData['widgets'] ? $userData['widgets'] : [],
                'desktop_apps' => $this->getStoredDesktopApps((int) ($user['uid'] ?? 0)),
            ],
        ]);
    }

    /**
     * 读取用户桌面快捷应用配置 (支持分块合并加载，突破 member_data varchar 500 限制)
     */
    private function getStoredDesktopApps(int $uid): array {
        if ($uid <= 0) {
            return [];
        }
        $fields = ['desktop_apps', 'desktop_apps_1', 'desktop_apps_2', 'desktop_apps_3'];
        $data = app('user.data')->get($uid, $fields);
        $merged = [];
        foreach ($fields as $field) {
            if (!empty($data[$field]) && is_array($data[$field])) {
                $merged = array_merge($merged, $data[$field]);
            }
        }
        return $merged;
    }

    /**
     * 保存用户桌面快捷应用配置 (按每块最多 15 项分块存储，每块 ~300 字符，确保绝不超出 member_data varchar 500)
     */
    private function saveStoredDesktopApps(int $uid, array $slim): void {
        if ($uid <= 0) {
            return;
        }
        $chunks = array_chunk($slim, 15);
        $fields = ['desktop_apps', 'desktop_apps_1', 'desktop_apps_2', 'desktop_apps_3'];
        $toSave = [];
        foreach ($fields as $index => $fieldName) {
            $chunkData = $chunks[$index] ?? [];
            $toSave[$fieldName] = !empty($chunkData) ? $chunkData : [];
        }
        app('user.data')->set($uid, $toSave);
    }

    /**
     * 用户桌面快捷应用配置与排序管理 (支持云端数据持久化保存)
     */
    public function desktopApps() {
        $user = $this->request->getAttribute('admin');
        if ($this->request->isMethod('post')) {
            $apps = input('apps', input('post.apps', []));
            if (empty($apps)) {
                $rawBody = (string) $this->request->getBody();
                if ($rawBody) {
                    $json = json_decode($rawBody, true);
                    if (is_array($json) && isset($json['apps'])) {
                        $apps = $json['apps'];
                    }
                }
            }
            if (is_string($apps)) {
                $apps = json_decode($apps, true) ?: [];
            }
            // 极简存储：系统项存 id/isSystem，自定义外部项仅存 id/title，绝不存大段 gradient 与 icon
            $slim = [];
            foreach ((array) $apps as $item) {
                if (!is_array($item) && !is_string($item)) {
                    continue;
                }
                $id = is_array($item) ? (string) ($item['id'] ?? '') : (string) $item;
                if (empty($id)) {
                    continue;
                }
                $entry = ['id' => $id];
                if (is_array($item) && !empty($item['isSystem'])) {
                    $entry['isSystem'] = 1;
                } else if (is_array($item)) {
                    if (!empty($item['title']) && $item['title'] !== $id && !str_starts_with((string) $item['title'], 'iframe:')) {
                        $entry['title'] = mb_substr((string) $item['title'], 0, 30);
                    }
                }
                $slim[] = $entry;
            }
            $this->saveStoredDesktopApps((int) ($user['uid'] ?? 0), $slim);
            show_json([
                'code' => 200,
                'message' => '保存成功',
                'data' => $slim,
                'uid' => $user['uid'],
            ]);
        }

        $apps = $this->getStoredDesktopApps((int) ($user['uid'] ?? 0));
        show_json([
            'code' => 200,
            'data' => $apps,
        ]);
    }

    /**
     * 获取必应 (Bing) 每日高清壁纸列表
     */
    /**
     * 网络请求辅助函数 (优先使用 curl 并设置合理超时，降级 file_get_contents)
     */
    private function fetchHttpData(string $url, int $timeout = 3): ?string {
        if (function_exists('curl_init')) {
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
                $data = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($data !== false && $httpCode >= 200 && $httpCode < 400) {
                    return (string) $data;
                }
            } catch (\Throwable) {}
        }
        try {
            $context = stream_context_create([
                'http' => ['timeout' => $timeout, 'user_agent' => 'Mozilla/5.0'],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $res = @file_get_contents($url, false, $context);
            return $res !== false ? (string) $res : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 获取必应 (Bing) 每日高清壁纸列表
     */
    public function bingWallpapers() {

        $url = 'https://www.bing.com/HPImageArchive.aspx?format=js&idx=0&n=8&mkt=zh-cn';
        $cacheKey = 'bing_wallpapers_v3';
        $list = xphp('cache')->get($cacheKey);
        try {
            if ($list && is_array($list) && isset($list[0]['url'])) {
                show_json([
                    'code' => 200,
                    'data' => $list
                ]);
            }

            $content = $this->fetchHttpData($url, 4);
            if ($content) {
                $json = json_decode($content, true);
                if (isset($json['images']) && is_array($json['images'])) {
                    $list = array_map(function ($img) {
                        $rawUrl = (string) ($img['url'] ?? '');
                        $imgUrl = str_starts_with($rawUrl, 'http') ? $rawUrl : 'https://www.bing.com' . $rawUrl;

                        return [
                            'title' => (string) ($img['title'] ?? ($img['copyright'] ?? '必应每日美图')),
                            'copyright' => (string) ($img['copyright'] ?? ''),
                            'url' => $imgUrl,
                            'date' => (string) ($img['enddate'] ?? ''),
                        ];
                    }, $json['images']);
                    xphp('cache')->set($cacheKey, $list, 86400);
                    show_json([
                        'code' => 200,
                        'data' => $list
                    ]);
                }
            }
        } catch (\Throwable $e) {
            isJsonException($e);
        }

        // 服务端离线降级美图列表（预置精确正上方实测色）
        show_json([
            'code' => 200,
            'data' => [
                [
                    'title' => '屋顶上的小镇 - 布哈兰塞',
                    'copyright' => '安达卢西亚小镇的连绵屋顶，布哈兰塞，西班牙',
                    'url' => 'https://www.bing.com/th?id=OHR.BujalanceRoofs_ZH-CN0285918754_1920x1080.jpg&rf=LaDigue_1920x1080.jpg&pid=hp',
                    'date' => '20260901',
                    'themeColor' => '#b87556',
                ],
                [
                    'title' => '图案艺术的典范 - 撒马尔罕',
                    'copyright' => '雷吉斯坦广场的建筑细节，撒马尔罕，乌兹别克斯坦',
                    'url' => 'https://www.bing.com/th?id=OHR.SamarkandCeiling_ZH-CN1818913296_1920x1080.jpg&rf=LaDigue_1920x1080.jpg&pid=hp',
                    'date' => '20260831',
                    'themeColor' => '#7d5329',
                ],
                [
                    'title' => '名为鲨鱼的巨型鱼类 - 鲸鲨',
                    'copyright' => '鲸鲨与黄金鲹，极乐鸟湾，西巴布亚，印度尼西亚',
                    'url' => 'https://www.bing.com/th?id=OHR.YellowShark_ZH-CN1570569826_1920x1080.jpg&rf=LaDigue_1920x1080.jpg&pid=hp',
                    'date' => '20260830',
                    'themeColor' => '#136b94',
                ],
                [
                    'title' => '读懂浪涛之间的讯息 - 圣卡塔琳娜州',
                    'copyright' => '冲浪者航拍图，圣卡塔琳娜州，巴西',
                    'url' => 'https://www.bing.com/th?id=OHR.SantaCatarina_ZH-CN4170292043_1920x1080.jpg&rf=LaDigue_1920x1080.jpg&pid=hp',
                    'date' => '20260829',
                    'themeColor' => '#aab5a7',
                ],
                [
                    'title' => '潮汐塑造的传奇 - 圣米歇尔山',
                    'copyright' => '涨潮时的圣米歇尔山，芒什省，诺曼底，法国',
                    'url' => 'https://www.bing.com/th?id=OHR.MichelSunset_ZH-CN0822968543_1920x1080.jpg&rf=LaDigue_1920x1080.jpg&pid=hp',
                    'date' => '20260828',
                    'themeColor' => '#ffe8c4',
                ],
                [
                    'title' => '湖水、野生动物与奇景 - 马加迪湖',
                    'copyright' => '日出时的小红鹳群，马加迪湖，肯尼亚',
                    'url' => 'https://www.bing.com/th?id=OHR.LakeMagadi_ZH-CN0601527009_1920x1080.jpg&rf=LaDigue_1920x1080.jpg&pid=hp',
                    'date' => '20260827',
                    'themeColor' => '#c96b5a',
                ],
                [
                    'title' => '流光溢彩的天空 - 冰岛极光',
                    'copyright' => '基尔丘山上空的极光，冰岛',
                    'url' => 'https://www.bing.com/th?id=OHR.AurorasIceland_ZH-CN9781322454_1920x1080.jpg&rf=LaDigue_1920x1080.jpg&pid=hp',
                    'date' => '20260826',
                    'themeColor' => '#0d2422',
                ],
                [
                    'title' => '守护美国瑰宝 - 红木国家公园',
                    'copyright' => '红木国家与州立公园的日出，加利福尼亚州，美国',
                    'url' => 'https://www.bing.com/th?id=OHR.RedwoodPark_ZH-CN9513051062_1920x1080.jpg&rf=LaDigue_1920x1080.jpg&pid=hp',
                    'date' => '20260825',
                    'themeColor' => '#d8985c',
                ],
                [
                    'title' => '跨越历史 - 布鲁克林大桥',
                    'copyright' => '布鲁克林大桥，纽约市，美国',
                    'url' => 'https://www.bing.com/th?id=OHR.BKBridge_ZH-CN3870511222_1920x1080.jpg&rf=LaDigue_1920x1080.jpg&pid=hp',
                    'date' => '20260824',
                    'themeColor' => '#1b2a3f',
                ],
            ]
        ]);
    }

    /**
     * 必应壁纸图片代理: 服务端回源 bing 图片并本地缓存后直出
     * 解决客户端网络无法直连 bing.com 时壁纸/缩略图加载失败 (整屏只剩兜底底色) 的问题
     * 图片为公开数据, SkipAuth 免登录直出; 仅允许 bing.com 域名, 杜绝 SSRF
     */
    #[SkipAuth]
    public function bingImage() {
        $imgUrl = (string) input('url', '');
        if ($imgUrl === '' || !preg_match('#^https?://([\w-]+\.)*bing\.com/#i', $imgUrl)) {
            show_json(['code' => 400, 'message' => 'Invalid bing image url']);
        }

        $cacheKey = 'bing_img_' . md5($imgUrl);
        $data = xphp('cache')->get($cacheKey);
        if (!is_string($data) || $data === '') {
            $data = $this->fetchHttpData($imgUrl, 8);
            if (!$data) {
                show_json(['code' => 404, 'message' => 'Fetch image failed']);
            }
            xphp('cache')->set($cacheKey, $data, 86400);
        }

        return response($data, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * 实时精确采样指定图片最上边缘 (y=0) 像素主色
     */
    public function imageTopColor() {
        $imgUrl = (string) input('url', input('post.url', ''));
        if (empty($imgUrl)) {
            $rawBody = (string) $this->request->getBody();
            if ($rawBody) {
                $json = json_decode($rawBody, true);
                if (is_array($json) && !empty($json['url'])) {
                    $imgUrl = (string) $json['url'];
                }
            }
        }
        if (empty($imgUrl) || !str_starts_with($imgUrl, 'http')) {
            show_json(['code' => 400, 'msg' => 'Invalid image url']);
        }
        $cacheKey = 'img_top_color_' . md5($imgUrl);
        $cached = xphp('cache')->get($cacheKey);
        if ($cached) {
            show_json(['code' => 200, 'data' => ['topColor' => $cached]]);
        }

        $topColor = null;
        try {
            $thumbUrl = $imgUrl . (str_contains($imgUrl, 'bing.com') ? (str_contains($imgUrl, '?') ? '&' : '?') . 'w=64' : '');
            $thumbData = $this->fetchHttpData($thumbUrl, 3);
            if ($thumbData) {
                $im = @imagecreatefromstring($thumbData);
                if ($im) {
                    $tw = imagesx($im);
                    $tr = 0; $tg = 0; $tb = 0; $tc = 0;
                    for ($x = 0; $x < $tw; $x += 2) {
                        $rgb = imagecolorat($im, $x, 0);
                        $tr += ($rgb >> 16) & 0xFF;
                        $tg += ($rgb >> 8) & 0xFF;
                        $tb += $rgb & 0xFF;
                        $tc++;
                    }
                    imagedestroy($im);
                    if ($tc > 0) {
                        $topColor = sprintf('#%02x%02x%02x', (int)($tr / $tc), (int)($tg / $tc), (int)($tb / $tc));
                        xphp('cache')->set($cacheKey, $topColor, 86400 * 7);
                    }
                }
            }
        } catch (\Throwable $e) {}

        if (!$topColor) {
            if (preg_match('/roof|bujalance|olivenza|spain/i', $imgUrl)) {
                $topColor = '#b87556';
            } elseif (preg_match('/shark|ocean|sea|water/i', $imgUrl)) {
                $topColor = '#136b94';
            } elseif (preg_match('/santacatarina|surf/i', $imgUrl)) {
                $topColor = '#aab5a7';
            } elseif (preg_match('/aurora|iceland/i', $imgUrl)) {
                $topColor = '#0d2422';
            }
        }

        show_json(['code' => 200, 'data' => ['topColor' => $topColor ?: '#1e3c72']]);
    }

    /**
     * 设备与运行环境信息(设置页-设备信息), 真实采集主机硬件与磁盘状态
     */
    public function device() {

        $hostname = (string) (php_uname('n') ?: gethostname());

        show_json([
            'code' => 200,
            'data' => [
                'hostname' => $hostname,
                'machine_id' => substr(sha1($hostname . '|' . php_uname()), 0, 40),
                'os' => php_uname('s') . ' ' . php_uname('r'),
                'arch' => php_uname('m'),
                'framework' => defined('VERSION') ? VERSION : '',
                'php' => PHP_VERSION,
                'swoole' => (string) (phpversion('swoole') ?: ''),
                'server_time' => date('Y-m-d H:i:s'),
                'uptime' => $this->osUptime(),
                'load' => function_exists('sys_getloadavg') ? array_map('floatval', sys_getloadavg()) : [0.0, 0.0, 0.0],
                'cpu' => $this->cpuInfo(),
                'memory' => $this->memoryInfo(),
                'disk' => $this->diskStat(BASE_PATH),
                'root_disk' => $this->diskStat(DIRECTORY_SEPARATOR),
                'ip' => $this->serverIp($hostname),
                'process_memory' => memory_get_usage(true),
            ],
        ]);
    }

    /**
     * 磁盘用量统计(字节)
     */
    private function diskStat(string $path): array {

        try {
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
        } catch (\Throwable) {
            $total = $free = null;
        }

        if (!$total || !$free) {
            return ['path' => $path, 'total' => 0, 'free' => 0, 'used' => 0, 'percent' => 0];
        }

        $used = (int) ($total - $free);
        return [
            'path' => $path,
            'total' => (int) $total,
            'free' => (int) $free,
            'used' => $used,
            'percent' => round($used / $total * 100, 1),
        ];
    }

    /**
     * 操作系统已运行秒数
     */
    private function osUptime(): int {

        if (is_readable('/proc/uptime')) {
            $content = (string) @file_get_contents('/proc/uptime');
            if (preg_match('/^(\d+)/', $content, $m)) {
                return (int) $m[1];
            }
        }

        // macOS / BSD
        $boottime = $this->shell('sysctl -n kern.boottime');
        if (preg_match('/sec\s*=\s*(\d+)/', $boottime, $m)) {
            return max(0, time() - (int) $m[1]);
        }

        return 0;
    }

    /**
     * CPU 型号与核心数
     */
    private function cpuInfo(): array {

        $cores = 0;
        $model = '';

        if (is_readable('/proc/cpuinfo')) {
            $content = (string) @file_get_contents('/proc/cpuinfo');
            if (preg_match_all('/model name\s*:\s*(.+)/', $content, $m)) {
                $model = trim((string) $m[1][0]);
            }
            if (preg_match_all('/^processor\s*:/m', $content, $m2)) {
                $cores = count($m2[0]);
            }
        }

        if (!$cores) {
            $cores = (int) $this->shell('sysctl -n hw.ncpu');
            $cores = $cores ?: (int) $this->shell('nproc');
        }

        if (!$model) {
            $model = $this->shell('sysctl -n machdep.cpu.brand_string');
        }

        return [
            'model' => $model ?: php_uname('m'),
            'cores' => $cores ?: 1,
        ];
    }

    /**
     * 物理内存总量与用量(字节); macOS 无法取到可用内存时 used 为 0
     */
    private function memoryInfo(): array {

        $total = 0;
        $available = null;

        if (is_readable('/proc/meminfo')) {
            $content = (string) @file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal\s*:\s*(\d+)\s*kB/', $content, $m)) {
                $total = (int) $m[1] * 1024;
            }
            if (preg_match('/MemAvailable\s*:\s*(\d+)\s*kB/', $content, $m2)) {
                $available = (int) $m2[1] * 1024;
            }
        }

        if (!$total) {
            $total = (int) $this->shell('sysctl -n hw.memsize');
        }

        $used = ($total > 0 && $available !== null) ? max(0, $total - $available) : 0;

        return [
            'total' => $total,
            'used' => $used,
            'percent' => ($total > 0 && $used > 0) ? round($used / $total * 100, 1) : 0,
        ];
    }

    /**
     * 服务端内网 IP; 解析失败返回空串
     */
    private function serverIp(string $hostname): string {

        try {
            $ip = gethostbyname($hostname);
            return $ip !== $hostname ? $ip : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 受控执行系统命令, 失败返回空串
     */
    private function shell(string $command): string {

        try {
            if (!function_exists('shell_exec')) {
                return '';
            }
            $out = @shell_exec($command);
            return is_string($out) ? trim($out) : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
