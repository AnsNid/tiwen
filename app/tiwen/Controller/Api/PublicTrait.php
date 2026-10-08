<?php

namespace App\tiwen\Controller\Api;

use App\tiwen\Service\ChatService;
use App\tiwen\Service\MailService;
use App\tiwen\Service\MemberService;
use App\tiwen\Service\SettingService;
use App\tiwen\Service\WebSearchService;

/**
 * 提问公共接口（无需登录）
 * 站点配置、模型清单、验证码、注册、登录、协议条款
 */
trait PublicTrait {

    use Responses;

    /**
     * 顶尖模型清单
     * @doc(method: 'get', description: '获取可比对的顶尖 AI 模型清单', tag: "公共")
     * @return array
     */
    public function models() {
        $providers = array_keys($this->container->get('config')->get('openai@config.providers', []));
        show_json([
            'code' => 200,
            'data' => (new ChatService())->modelList(),
            'providers' => array_values($providers),
        ]);
    }

    /**
     * 模块配置
     * @doc(method: 'get', description: '获取站点配置、默认对比模型组与套餐定义', tag: "公共")
     * @return array
     */
    public function config() {
        $setting = new SettingService();
        $plans = $setting->plans();
        show_json([
            'code' => 200,
            'data' => [
                'site_name' => '提问',
                'description' => '问得更准，比对所有顶尖 AI 模型',
                'default_models' => (array) config('tiwen@config.default_models', []),
                'default_provider' => (string) $this->container->get('config')->get('openai@config.default_provider', 'apilio'),
                'currency' => $setting->currency(),
                'currency_symbol' => $setting->currencySymbol(),
                'plans' => $plans,
                'web_search_enabled' => (bool) ($setting->webSearch()['enabled']),
            ],
        ]);
    }

    /**
     * 发送邮箱验证码
     * @doc(method: 'post', description: '发送注册验证码到邮箱(10 分钟有效,60 秒防刷)', tag: "认证")
     * @param string $email 邮箱
     * @return array
     */
    public function sendCode() {
        $email = trim((string) input('email', ''));
        $result = (new MailService())->sendCode($email);
        if ($result['ok']) {
            return $this->ok(['email' => $email], $result['message']);
        }
        return $this->fail($result['message']);
    }

    /**
     * 注册(邮箱 + 验证码 + 密码)
     * @doc(method: 'post', description: '邮箱验证码注册,成功返回 token 自动登录', tag: "认证")
     * @param string $email 邮箱
     * @param string $code 邮箱验证码
     * @param string $password 密码(不少于 6 位)
     * @return array
     */
    public function register() {
        $email = trim((string) input('email', ''));
        $code = trim((string) input('code', ''));
        $password = (string) input('password', '');

        if ($email === '' || $code === '' || $password === '') {
            return $this->fail('邮箱、验证码和密码不能为空');
        }
        $mail = new MailService();
        if (!$mail->verifyCode($email, $code)) {
            return $this->fail('验证码错误或已过期');
        }
        $result = (new MemberService())->register($email, $password);
        if (!$result['ok']) {
            return $this->fail($result['message']);
        }
        return $this->ok($result['data'] ?? null, $result['message']);
    }

    /**
     * 登录(邮箱 + 密码)
     * @doc(method: 'post', description: '邮箱密码登录,返回 token 与用户档案', tag: "认证")
     * @param string $email 邮箱
     * @param string $password 密码
     * @return array
     */
    public function login() {
        $email = trim((string) input('email', ''));
        $password = (string) input('password', '');
        $result = (new MemberService())->login($email, $password);
        if (!$result['ok']) {
            return $this->fail($result['message']);
        }
        return $this->ok($result['data'], $result['message']);
    }

    /**
     * 发送密码重置验证码(免登录)
     * @doc(method: 'post', description: '向已注册邮箱发送密码重置验证码(10 分钟有效,60 秒防刷)', tag: "认证")
     * @param string $email 邮箱
     * @return array
     */
    public function resetCode() {
        $email = trim((string) input('email', ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('邮箱格式不正确');
        }
        if (!m('user.Api')->where('email', strtolower($email))->value('uid')) {
            return $this->fail('该邮箱尚未注册');
        }
        $result = (new MailService())->sendCode($email, 'reset');
        if (!$result['ok']) {
            return $this->fail($result['message']);
        }
        return $this->ok(['email' => $email], $result['message']);
    }

    /**
     * 重置密码(邮箱 + 验证码 + 新密码)
     * @doc(method: 'post', description: '校验邮箱验证码后重置登录密码,重置成功请使用新密码登录', tag: "认证")
     * @param string $email 邮箱
     * @param string $code 邮箱验证码
     * @param string $password 新密码(不少于 6 位)
     * @return array
     */
    public function resetPassword() {
        $email = trim((string) input('email', ''));
        $code = trim((string) input('code', ''));
        $password = (string) input('password', '');
        $result = (new MemberService())->resetPassword($email, $code, $password);
        if (!$result['ok']) {
            return $this->fail($result['message']);
        }
        return $this->ok(null, $result['message']);
    }

    /**
     * 协议文档内容(免登录)
     * @doc(method: 'get', description: '按文档与语言获取协议条款 Markdown 内容,未配置返回空串由前台兜底', tag: "公共")
     * @param string $doc 文档键: register|privacy|about|payment
     * @param string $lang 语言: zh|zh-TW|en
     * @return array {code: 200, data: {content: string}}
     */
    public function legal() {
        $doc = trim((string) input('doc', ''));
        $lang = trim((string) input('lang', ''));
        if (!in_array($doc, SettingService::LEGAL_DOCS, true) || !in_array($lang, SettingService::LEGAL_LANGS, true)) {
            return $this->fail('参数不正确');
        }
        return $this->ok([
            'content' => (new SettingService())->legalContent($doc, $lang),
        ]);
    }
}
