<?php

declare(strict_types=1);

namespace App\openai\Model;

use xphp\DbConnection\Model\Model;

/**
 * 模型配置
 *
 * 一行 = 某供应商下的一个模型(支持以 * 结尾的前缀规则)。
 * 同一模型出现在多个供应商下时，priority 最小者胜出，即 model_provider_map 的优先级语义。
 */
class ProviderModel extends Model {

    protected ?string $table = 'openai_models';

    protected string $primaryKey = 'id';

    /**
     * 全部启用模型，按优先级排序返回(路由取每个模型的第一条)
     *
     * @return array<int,array<string,mixed>>
     */
    public function actives(): array {
        return $this->newQuery()
            ->where('status', 1)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->toArray();
    }
}
