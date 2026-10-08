<?php

namespace App\consume;

use xphp\Contract\ConfigInterface;

/**
 * 积分规则
 */
class Rule {

    public function __construct(protected ConfigInterface $config) {
    }

    /**
     * 获取配置
     * @return array
     */
    public function getConfig(): array {
        // 自定义
        $config = $this->config->sysget('consumerule', []);
        if (empty($config) || !is_array($config)) {
            $config = $this->config->get('consume@config.rule', []);
        }
        return $config;
    }

    /**
     * 获取规则
     * @param string $type
     * @return array
     */
    public function getRule(string|array $type): array {
        $config = $this->getConfig();
        if (is_array($type)) {
            $ruletype = $type['type'] ?? '';
            return $this->convertConfig(array_merge($config[$ruletype] ?? [], $type));
        }
        return $this->convertConfig($config[$type] ?? []);
    }

    /**
     * 内部函数 处理 amount
     */
    public function handleRuleAmount(mixed $amount): float {

        if (is_numeric($amount)) {
            return (float)$amount;
        }

        if (is_string($amount)) {
            // 处理范围: 1-100
            if (str_contains($amount, '-')) {
                $parts = array_map('intval', explode('-', $amount));
                if (count($parts) >= 2) {
                    $min = max($parts[0], 1);
                    $max = min($parts[1], 10000);
                    if ($min <= $max) {
                        return (float)mt_rand($min, $max);
                    }
                }
            }

            // 处理列表: 10,20,30
            if (str_contains($amount, ',')) {
                $amount = explode(',', $amount);
            }
        }

        // 处理数组 (包括从列表转换来的)
        if (is_array($amount)) {
            $values = array_values(array_unique(array_filter(array_map('intval', $amount))));
            if (!empty($values)) {
                return (float)$values[array_rand($values)];
            }
        }

        return (float)$amount;
    }

    /**
     * 转化配置
     */
    public function convertConfig(array $config): array {

        $restrict = $config['restrict'] ?? [];

        if (isset($restrict['amount']) && $restrict['amount'] !== '') {
            $restrict['amount'] = $this->handleRuleAmount($restrict['amount']);
        }

        if (isset($restrict['count'])) {
            $restrict['count'] = (int)$restrict['count'];
        }

        if (!empty($restrict['date'])) {
            $restrict['date'] = $this->convertDate($restrict['date']);
        }

        if (($restrict['count'] ?? 0) == 0 && ($restrict['amount'] ?? 0) == 0) {
            $restrict['date'] = [];
        }

        $config['restrict'] = $restrict;
        return $config;
    }

    /**
     * 转化日期
     */
    public function convertDate(mixed $date): array {

        if (is_array($date) && count($date) >= 2) {
            return [
                date('Y-m-d', strtotime($date[0])),
                date('Y-m-d 23:59:59', strtotime($date[1]))
            ];
        }

        return match ($date) {
            'daily' => [
                date('Y-m-d'),
                date('Y-m-d 23:59:59')
            ],
            'weekly' => [
                date('Y-m-d', strtotime('this week')),
                date('Y-m-d 23:59:59', strtotime('next week -1 day'))
            ],
            'monthly' => [
                date('Y-m-01'),
                date('Y-m-d 23:59:59', strtotime('+1 month -1 day'))
            ],
            default => [],
        };
    }

    /**
     * 转化有效期为到期时间, null 表示永久有效
     *
     * 数字按天计(30 即发放后 30 天), 字符串交给 strtotime: 相对式 '72 hours'
     * 与绝对式 '2026-12-31 23:59:59' 都能解析。
     *
     * 必须在发放时求值, 不能在 convertConfig 里预算: Swoole 常驻进程下配置只在
     * 启动时加载一次, 预算出的绝对时间会被后续所有请求复用。
     */
    public function convertExpire(mixed $expire): ?string {

        if (is_numeric($expire)) {
            $seconds = (int) round((float) $expire * 86400);
            return $seconds > 0 ? date('Y-m-d H:i:s', time() + $seconds) : null;
        }

        if (!is_string($expire) || trim($expire) === '') {
            return null;
        }

        $timestamp = strtotime(trim($expire));
        if ($timestamp === false) {
            logger()->warning('consume 规则有效期无法解析, 已按永久有效处理', ['expire' => $expire]);
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
