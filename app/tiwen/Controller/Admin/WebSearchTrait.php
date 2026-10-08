<?php

namespace App\tiwen\Controller\Admin;

use App\tiwen\Controller\Api\Responses;
use App\tiwen\Service\SettingService;
use xphp\HttpServer\Annotation\Middleware;
use App\tiwen\Middleware\AdminAuthMiddleware;

/**
 * 后台联网搜索(Tavily)配置接口（需 tiwen:admin 权限）
 * 前台勾选「智能搜索」时对问题预检索实时网页并注入各模型上下文;此处管理开关、Key 与检索参数
 */
#[Middleware(AdminAuthMiddleware::class)]
trait WebSearchTrait {

    use Responses;

    /**
     * 联网搜索配置查看
     * @doc(method: 'get', description: '获取联网搜索(Tavily)配置(api_key 脱敏,仅返回是否已配置与掩码)', tag: "后台-联网搜索")
     * @return array {code: 200, data: {webSearch: {enabled, api_key_set, api_key_masked, max_results, search_depth, include_answer, timeout, max_content_chars}}}
     */
    public function webSearch() {
        return $this->ok([
            'webSearch' => (new SettingService())->webSearchForAdmin(),
        ]);
    }

    /**
     * 联网搜索配置保存
     * @doc(method: 'post', description: '保存联网搜索配置,前台即刻生效;api_key 传空串表示保留原值不修改', tag: "后台-联网搜索")
     * @param array $webSearch 配置对象(enabled/api_key/max_results/search_depth/include_answer/timeout/max_content_chars)
     * @return array {code: 200, message: '联网搜索配置已保存'}
     */
    public function webSearchSave() {
        $raw = input('webSearch', []);
        if (!is_array($raw)) {
            return $this->fail('配置数据格式不正确');
        }
        $ok = (new SettingService())->saveWebSearch($raw);
        return $ok ? $this->ok(null, '联网搜索配置已保存,前台勾选即刻生效') : $this->fail('保存失败');
    }
}
