<?php

declare(strict_types=1);

namespace App\user;

use Throwable;

/**
 * 设备指纹
 * 登录时写入 token 第4段, 请求时重新计算比对, 换设备访问 token 即失效
 * 只取 User-Agent 这类设备级特征, 不含 IP 等网络级特征(移动网络 IP 漂移会造成误杀)
 */
class Device {

    /**
     * 计算请求设备指纹, 兼容 PSR-7 请求(getHeaderLine)与框架 request 包装(header)
     */
    public static function fingerprint(mixed $request = null): string {

        $userAgent = self::header($request, 'user-agent');
        if ($userAgent === '') {
            return '';
        }

        return substr(md5($userAgent), 0, 16);
    }

    /**
     * 读取请求头
     */
    private static function header(mixed $request, string $name): string {

        try {
            if ($request && method_exists($request, 'getHeaderLine')) {
                return trim((string) $request->getHeaderLine($name));
            }
            if ($request && method_exists($request, 'header')) {
                return trim((string) $request->header($name));
            }
        } catch (Throwable) {
        }
        return '';
    }
}
