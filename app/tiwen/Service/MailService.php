<?php

declare(strict_types=1);

namespace App\tiwen\Service;

/**
 * 邮件验证码服务
 * 复用 mail 应用 SDK 发送,验证码存 Redis(TTL 10 分钟,60 秒防刷)
 */
class MailService {

    private const KEY_CODE = 'tiwen:code:%s';
    private const KEY_LIMIT = 'tiwen:code:limit:%s';

    private int $ttl;
    private int $interval;

    public function __construct() {
        $config = config('tiwen@config', []);
        $this->ttl = (int) ($config['code_ttl'] ?? 600);
        $this->interval = (int) ($config['code_interval'] ?? 60);
    }

    /**
     * 发送验证码
     * @param string $scene 场景: register(注册)|reset(密码找回),决定邮件文案
     * @return array{ok: bool, message: string}
     */
    public function sendCode(string $email, string $scene = 'register'): array {
        $email = trim(strtolower($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => '邮箱格式不正确'];
        }

        $redis = xphp('redis');
        $limitKey = sprintf(self::KEY_LIMIT, md5($email));
        if ($redis->exists($limitKey)) {
            $wait = (int) $redis->ttl($limitKey);
            return ['ok' => false, 'message' => '发送太频繁,请 ' . max(1, $wait) . ' 秒后重试'];
        }

        $code = (string) random_int(100000, 999999);
        $html = $this->renderTemplate($code, (int) ceil($this->ttl / 60), $scene);

        // 验证码先落 Redis:即使邮件在途,用户收到后即可校验
        $codeKey = sprintf(self::KEY_CODE, md5($email));
        $redis->setex($codeKey, $this->ttl, $code);
        $redis->setex($limitKey, $this->interval, '1');

        $this->queueMail($email, $html, $scene);
        return ['ok' => true, 'message' => '验证码已发送,请查收邮件'];
    }

    /**
     * 邮件队列化投递:HTTP 协程环境下派发后台协程发送,接口即刻返回不阻塞;
     * 失败自动重试一次,最终失败仅记日志(验证码已在 Redis,用户可重发)
     */
    private function queueMail(string $to, string $html, string $scene = 'register'): void {
        $subject = $scene === 'reset' ? '提问 Tiwen 密码重置验证码' : '提问 Tiwen 注册验证码';
        $send = function () use ($to, $html, $subject) {
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $mailer = xphp(\App\mail\Contract\Mailer::class);
                    // 必须用 html()(内部包 HtmlString):直接 send(['html' => $string]) 会把字符串当视图名渲染,正文为空
                    $mailer->html($html, function ($m) use ($to, $subject) {
                        $m->to($to);
                        $m->subject($subject);
                    });
                    return;
                } catch (\Throwable $e) {
                    logger()->error("tiwen 验证码邮件发送失败(第 {$attempt} 次,收件人 {$to}): " . $e->getMessage());
                    if ($attempt < 2 && class_exists(\Swoole\Coroutine::class)) {
                        \Swoole\Coroutine::sleep(3);
                    }
                }
            }
        };

        // 请求协程内 → 派发独立后台协程;非协程环境(CLI)降级同步发送
        if (class_exists(\Swoole\Coroutine::class) && \Swoole\Coroutine::getCid() >= 0) {
            \Swoole\Coroutine::create($send);
        } else {
            $send();
        }
    }

    /**
     * 校验验证码(通过即失效)
     */
    public function verifyCode(string $email, string $code): bool {
        $email = trim(strtolower($email));
        $code = trim($code);
        if ($email === '' || $code === '') {
            return false;
        }
        $redis = xphp('redis');
        $codeKey = sprintf(self::KEY_CODE, md5($email));
        $saved = (string) $redis->get($codeKey);
        if ($saved === '' || $saved !== $code) {
            return false;
        }
        $redis->del($codeKey);
        return true;
    }

    /**
     * 验证码邮件 HTML 模板
     */
    private function renderTemplate(string $code, int $minutes, string $scene = 'register'): string {
        $escape = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $greeting = $scene === 'reset'
            ? '您好,您正在重置提问 Tiwen 的登录密码,验证码为:'
            : '您好,欢迎注册提问 Tiwen!您的注册验证码为:';
        $action = $scene === 'reset' ? '请尽快完成密码重置。' : '请尽快完成注册。';
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif;">
  <div style="max-width:520px;margin:40px auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">
    <div style="padding:28px 32px;background:linear-gradient(135deg,#2f6bff,#6366f1);color:#fff;">
      <div style="font-size:18px;font-weight:700;">提问 Tiwen</div>
      <div style="font-size:12px;opacity:.85;margin-top:4px;">问得更准,比对所有顶尖 AI 模型</div>
    </div>
    <div style="padding:32px;">
      <p style="margin:0 0 12px;font-size:14px;color:#334155;">{$escape($greeting)}</p>
      <div style="text-align:center;margin:24px 0;">
        <span style="display:inline-block;padding:12px 32px;background:#f1f5f9;border:1px dashed #2f6bff;border-radius:12px;font-size:30px;font-weight:800;letter-spacing:8px;color:#2f6bff;font-family:'SF Mono',Consolas,monospace;">{$escape($code)}</span>
      </div>
      <p style="margin:0;font-size:12px;color:#64748b;line-height:1.8;">
        验证码 {$minutes} 分钟内有效,{$action}<br>
        若非本人操作,请忽略本邮件,您的账户不会受到影响。
      </p>
    </div>
    <div style="padding:16px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;text-align:center;">
      此邮件由系统自动发送,请勿直接回复 · 提问 Tiwen
    </div>
  </div>
</body>
</html>
HTML;
    }
}
