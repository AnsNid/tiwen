<?php

namespace App\tiwen\Controller\Admin;

use App\tiwen\Controller\Api\Responses;
use App\tiwen\Service\SettingService;
use xphp\HttpServer\Annotation\Middleware;
use App\tiwen\Middleware\AdminAuthMiddleware;

/**
 * 后台协议条款管理接口（需 tiwen:admin 权限）
 * 注册协议 / 隐私条款 / 关于我们 / 支付条款,简体/繁体/英文分开维护,保存后前台即刻生效
 */
#[Middleware(AdminAuthMiddleware::class)]
trait LegalTrait {

    use Responses;

    /**
     * 协议文档查看
     * @doc(method: 'get', description: '获取注册协议/隐私条款/关于我们/支付条款的简体/繁体/英文内容(Markdown)', tag: "后台-协议条款")
     * @return array {code: 200, data: {legal: {register: {zh, zh-TW, en, customized}, privacy: {...}, about: {...}, payment: {...}}}}
     */
    public function legal() {
        return $this->ok([
            'legal' => (new SettingService())->legal(),
        ]);
    }

    /**
     * 协议文档保存
     * @doc(method: 'post', description: '按文档与语言保存 Markdown 内容,传空串即下线该篇', tag: "后台-协议条款")
     * @param string $doc 文档键: register|privacy|about|payment
     * @param string $lang 语言: zh|zh-TW|en
     * @param string $value 文档内容(Markdown,最长 20000 字,空串下线)
     * @return array {code: 200, message: '文档已保存'}
     */
    public function legalSave() {
        $doc = trim((string) input('doc', ''));
        $lang = trim((string) input('lang', ''));
        $value = (string) input('value', '');
        if (!in_array($doc, SettingService::LEGAL_DOCS, true)) {
            return $this->fail('文档键不正确');
        }
        if (!in_array($lang, SettingService::LEGAL_LANGS, true)) {
            return $this->fail('语言不正确');
        }
        $ok = (new SettingService())->saveLegal($doc, $lang, $value);
        return $ok ? $this->ok(null, '文档已保存,前台即刻生效') : $this->fail('保存失败');
    }
}
