<?php

namespace App\tiwen\Controller\Admin;

use App\tiwen\Controller\Api\Responses;
use App\tiwen\Service\SettingService;
use xphp\HttpServer\Annotation\Middleware;
use App\tiwen\Middleware\AdminAuthMiddleware;

/**
 * 后台提示词管理接口（需 tiwen:admin 权限）
 * 对话与综合分析的系统提示词查看/保存,保存后新会话即刻生效
 */
#[Middleware(AdminAuthMiddleware::class)]
trait PromptsTrait {

    use Responses;

    /**
     * 提示词查看
     * @doc(method: 'get', description: '获取对话/综合分析系统提示词(含默认值与是否已自定义)', tag: "后台-提示词")
     * @return array {code: 200, data: {chat: {value, default, customized}, synthesis: {...}}}
     */
    public function prompts() {
        return $this->ok([
            'prompts' => (new SettingService())->prompts(),
        ]);
    }

    /**
     * 提示词保存
     * @doc(method: 'post', description: '保存系统提示词,传空串恢复默认;对话提示词为空时不注入 system', tag: "后台-提示词")
     * @param string $key 提示词键: chat|synthesis
     * @param string $value 提示词内容(空串恢复默认)
     * @return array {code: 200, message: '提示词已保存'}
     */
    public function promptsSave() {
        $key = trim((string) input('key', ''));
        $value = (string) input('value', '');
        if (!in_array($key, ['chat', 'synthesis'], true)) {
            return $this->fail('提示词键不正确');
        }
        $ok = (new SettingService())->savePrompt($key, $value);
        return $ok ? $this->ok(null, '提示词已保存,新对话即刻生效') : $this->fail('保存失败');
    }
}
