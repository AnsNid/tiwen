<?php

declare(strict_types=1);

namespace App\openai\Model;

use xphp\DbConnection\Model\Model;

/**
 * 调用看板日聚合
 *
 * 独立于 openai_task_usages 明细存储：明细表只覆盖 Task::run，且会随清理丢失，
 * 看板要的是"按供应商/按模型"的长期累计。维度 (stats_date, provider, account, model)。
 *
 * 流式调用(createStreamed)拿不到 usage，token 记 0，调用次数与耗时仍然准确。
 */
class StatsDaily extends Model {

    protected ?string $table = 'openai_stats_daily';

    protected string $primaryKey = 'id';

    /**
     * 可累加字段白名单，防止 SQL 注入
     */
    private const FIELDS = ['total', 'success', 'failed', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'duration_ms'];

    /**
     * 原子累加：不存在则插入初始值，存在则字段自增
     *
     * @param array<string,int> $fields 只支持白名单字段，如 ['total' => 1, 'success' => 1, 'total_tokens' => 128]
     */
    public function record(string $provider, string $account, string $model, array $fields, ?string $date = null): void {
        $date = $date ?: date('Y-m-d');

        $increments = [];
        foreach ($fields as $field => $amount) {
            $amount = (int) $amount;
            if (in_array($field, self::FIELDS, true) && $amount > 0) {
                $increments[$field] = $amount;
            }
        }
        if (!$increments) {
            return;
        }

        $base = array_merge(
            [
                'stats_date' => $date,
                // 维度值截到列宽，避免个别网关返回超长模型名时整条统计被丢弃
                'provider' => mb_substr($provider, 0, 32),
                'account' => mb_substr($account, 0, 64),
                'model' => mb_substr($model, 0, 128),
            ],
            array_fill_keys(self::FIELDS, 0),
            $increments
        );

        $update = [];
        foreach ($increments as $field => $amount) {
            $update[$field] = dbraw("`{$field}` + " . $amount);
        }

        $this->upsert([$base], ['stats_date', 'provider', 'account', 'model'], $update);
    }

    /**
     * 概览：调用量、成功率、token、平均耗时
     *
     * @return array{total_calls:int,success:int,failed:int,success_rate:float,total_tokens:int,prompt_tokens:int,completion_tokens:int,avg_duration_ms:int}
     */
    public function overview(?string $startDate = null, ?string $endDate = null): array {
        $query = $this->newQuery()->selectRaw(
            'COALESCE(SUM(total), 0) as total_calls, COALESCE(SUM(success), 0) as success, COALESCE(SUM(failed), 0) as failed,
             COALESCE(SUM(prompt_tokens), 0) as prompt_tokens, COALESCE(SUM(completion_tokens), 0) as completion_tokens,
             COALESCE(SUM(total_tokens), 0) as total_tokens, COALESCE(SUM(duration_ms), 0) as duration_ms'
        );
        $this->applyRange($query, $startDate, $endDate);

        $row = (array) $query->one();
        $total = (int) ($row['total_calls'] ?? 0);
        $success = (int) ($row['success'] ?? 0);
        $failed = (int) ($row['failed'] ?? 0);

        return [
            'total_calls' => $total,
            'success' => $success,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round($success / $total * 100, 1) : 0,
            'prompt_tokens' => (int) ($row['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($row['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($row['total_tokens'] ?? 0),
            'avg_duration_ms' => $total > 0 ? (int) round((int) ($row['duration_ms'] ?? 0) / $total) : 0,
        ];
    }

    /**
     * 按供应商聚合(同 provider 的多个账号合并)
     *
     * @return array<int,array<string,mixed>>
     */
    public function byProvider(?string $startDate = null, ?string $endDate = null, int $limit = 0): array {
        return $this->groupByDimension('provider', $startDate, $endDate, $limit);
    }

    /**
     * 按模型聚合
     *
     * @return array<int,array<string,mixed>>
     */
    public function byModel(?string $startDate = null, ?string $endDate = null, int $limit = 0): array {
        return $this->groupByDimension('model', $startDate, $endDate, $limit);
    }

    /**
     * 按账号聚合(账号池内各账号的用量与健康度)
     *
     * @return array<int,array<string,mixed>>
     */
    public function byAccount(?string $startDate = null, ?string $endDate = null, int $limit = 0): array {
        $query = $this->newQuery()
            ->selectRaw(
                'provider, account, SUM(total) as total_calls, SUM(success) as success, SUM(failed) as failed,
                 SUM(total_tokens) as total_tokens, SUM(duration_ms) as duration_ms'
            )
            ->groupBy('provider')
            ->groupBy('account')
            ->orderByDesc('total_calls');
        $this->applyRange($query, $startDate, $endDate);
        if ($limit > 0) {
            $query->limit($limit);
        }
        return array_map([$this, 'decorate'], $query->get()->toArray());
    }

    /**
     * 按天趋势(补零)，可按供应商/模型下钻
     *
     * @return array<int,array{date:string,total_calls:int,success:int,failed:int,total_tokens:int}>
     */
    public function trend(int $days = 7, ?string $provider = null, ?string $model = null, ?string $startDate = null, ?string $endDate = null): array {
        if ($startDate && $endDate) {
            $days = 0;
        } else {
            $days = max(1, min(365, $days));
            $endDate = date('Y-m-d');
            $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        }

        $query = $this->newQuery()
            ->selectRaw('stats_date as date, SUM(total) as total_calls, SUM(success) as success, SUM(failed) as failed, SUM(total_tokens) as total_tokens')
            ->where('stats_date', '>=', $startDate)
            ->where('stats_date', '<=', $endDate)
            ->groupBy('date')
            ->orderBy('date');
        if ($provider !== null && $provider !== '') {
            $query->where('provider', $provider);
        }
        if ($model !== null && $model !== '') {
            $query->where('model', $model);
        }

        $map = array_column($query->get()->toArray(), null, 'date');

        $trend = [];
        try {
            $period = new \DatePeriod(
                new \DateTime($startDate),
                new \DateInterval('P1D'),
                (new \DateTime($endDate))->modify('+1 day')
            );
            foreach ($period as $dt) {
                if (count($trend) >= 1000) {
                    break;
                }
                $date = $dt->format('Y-m-d');
                $row = $map[$date] ?? null;
                $trend[] = [
                    'date' => $date,
                    'total_calls' => (int) ($row['total_calls'] ?? 0),
                    'success' => (int) ($row['success'] ?? 0),
                    'failed' => (int) ($row['failed'] ?? 0),
                    'total_tokens' => (int) ($row['total_tokens'] ?? 0),
                ];
            }
        } catch (\Throwable) {
            // 日期区间非法时返回已聚合部分
        }
        return $trend;
    }

    /**
     * 单维度聚合的公共实现
     */
    private function groupByDimension(string $column, ?string $startDate, ?string $endDate, int $limit): array {
        // 维度列名只来自本类内部常量调用点，不接受外部输入
        $query = $this->newQuery()
            ->selectRaw(
                "{$column}, SUM(total) as total_calls, SUM(success) as success, SUM(failed) as failed,
                 SUM(prompt_tokens) as prompt_tokens, SUM(completion_tokens) as completion_tokens,
                 SUM(total_tokens) as total_tokens, SUM(duration_ms) as duration_ms"
            )
            ->groupBy($column)
            ->orderByDesc('total_calls');
        $this->applyRange($query, $startDate, $endDate);
        if ($limit > 0) {
            $query->limit($limit);
        }
        return array_map([$this, 'decorate'], $query->get()->toArray());
    }

    private function applyRange($query, ?string $startDate, ?string $endDate): void {
        if ($startDate) {
            $query->where('stats_date', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('stats_date', '<=', $endDate);
        }
    }

    /**
     * 补上派生指标：成功率与平均耗时
     */
    private function decorate(array $row): array {
        $total = (int) ($row['total_calls'] ?? 0);
        $row['total_calls'] = $total;
        $row['success'] = (int) ($row['success'] ?? 0);
        $row['failed'] = (int) ($row['failed'] ?? 0);
        $row['prompt_tokens'] = (int) ($row['prompt_tokens'] ?? 0);
        $row['completion_tokens'] = (int) ($row['completion_tokens'] ?? 0);
        $row['total_tokens'] = (int) ($row['total_tokens'] ?? 0);
        $row['success_rate'] = $total > 0 ? round($row['success'] / $total * 100, 1) : 0;
        $row['avg_duration_ms'] = $total > 0 ? (int) round((int) ($row['duration_ms'] ?? 0) / $total) : 0;
        unset($row['duration_ms']);
        return $row;
    }
}
