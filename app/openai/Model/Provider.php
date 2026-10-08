<?php

declare(strict_types=1);

namespace App\openai\Model;

use xphp\DbConnection\Model\Model;

/**
 * 供应商账号池
 *
 * 一行 = 一个账号(一把 key)。同一 provider 下的多行构成该供应商的账号池，
 * 调用时按 priority 升序取第一个启用的账号。
 */
class Provider extends Model {

    protected ?string $table = 'openai_providers';

    protected string $primaryKey = 'id';

    /**
     * 全部启用账号，按 provider 分组所需的排序返回
     *
     * priority 升序(数字越小越优先)，同优先级按 id 升序保证选择结果稳定。
     *
     * @return array<int,array<string,mixed>>
     */
    public function actives(): array {
        return $this->newQuery()
            ->where('status', 1)
            ->orderBy('provider')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->toArray();
    }

    /**
     * 已配置的 provider 分组清单(含禁用账号的分组)，供后台下拉与导入使用
     *
     * @return array<int,string>
     */
    public function providerNames(): array {
        $rows = $this->newQuery()
            ->select('provider')
            ->groupBy('provider')
            ->orderBy('provider')
            ->get()
            ->toArray();
        return array_values(array_filter(array_column($rows, 'provider'), fn ($v) => is_string($v) && $v !== ''));
    }
}
