<?php

declare(strict_types=1);

namespace App\openai\Controller\Admin;

use App\admin\Authorization;
use Throwable;

/**
 * 调用看板
 * 数据源为 openai_stats_daily 日聚合表，由 ResourceProxy 在每次 LLM 调用后写入，
 * 覆盖各业务模块的直接调用(不只是 Task::run)
 */
class Board extends Authorization {

    /**
     * 看板数据 (概览 + 按供应商 + 按模型 + 按账号 + 趋势，一次请求取全)
     */
    public function index() {

        $days = (int) input('.days', 7, 'intval');
        $startDate = $this->normalizeDate((string) input('.start_date', '', 'trim'));
        $endDate = $this->normalizeDate((string) input('.end_date', '', 'trim'));
        $provider = (string) input('.provider', '', 'trim');
        $model = (string) input('.model', '', 'trim');

        try {
            $stats = m('openai.StatsDaily');

            // 未指定区间时：days > 0 取最近 N 天，days <= 0 取表内全部有数据的区间
            if ($startDate === '' || $endDate === '') {
                if ($days > 0) {
                    $endDate = date('Y-m-d');
                    $startDate = date('Y-m-d', strtotime('-' . (min(365, $days) - 1) . ' days'));
                } else {
                    [$startDate, $endDate] = $this->dataRange();
                }
            }

            $data = [
                'range' => ['start_date' => $startDate, 'end_date' => $endDate, 'days' => $days],
                'overview' => $stats->overview($startDate ?: null, $endDate ?: null),
                'by_provider' => $stats->byProvider($startDate ?: null, $endDate ?: null),
                'by_model' => $stats->byModel($startDate ?: null, $endDate ?: null, 50),
                'by_account' => $stats->byAccount($startDate ?: null, $endDate ?: null),
                'trend' => $stats->trend(0, $provider, $model, $startDate ?: null, $endDate ?: null),
                'available' => true,
                'error' => '',
            ];
        } catch (Throwable $e) {
            // 表还没建时给出明确提示而不是让整个后台页报错；同时带上原始错误便于区分建表缺失与真实 SQL 故障
            $data = [
                'range' => ['start_date' => $startDate, 'end_date' => $endDate, 'days' => $days],
                'overview' => null,
                'by_provider' => [],
                'by_model' => [],
                'by_account' => [],
                'trend' => [],
                'available' => false,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ];
        }

        show_json(['code' => 200, 'data' => $data]);
    }

    /**
     * 表内最早与最晚的统计日期，用于"全部时间"区间
     *
     * @return array{0:string,1:string}
     */
    private function dataRange(): array {

        try {
            $row = (array) db('openai_stats_daily')
                ->selectRaw('MIN(stats_date) as min_date, MAX(stats_date) as max_date')
                ->first();
        } catch (Throwable) {
            return ['', ''];
        }

        $today = date('Y-m-d');
        return [
            $this->normalizeDate((string) ($row['min_date'] ?? '')) ?: $today,
            $this->normalizeDate((string) ($row['max_date'] ?? '')) ?: $today,
        ];
    }

    private function normalizeDate(string $date): string {

        $date = trim($date);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    }
}
