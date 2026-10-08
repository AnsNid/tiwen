<?php

declare(strict_types=1);

namespace App\mail\Controller\Admin;

use App\admin\Authorization;
use App\mail\Contract\Mailer;
use App\mail\MailManager;
use Throwable;

use function xphp\Collection\data_set;

/**
 * 邮件服务管理
 * 配置持久化于 config 表 (mail_* 前缀), 经 config 缓存文件下发全部 worker;
 * MailManager 读取时以 sysget('config.mail_*') 覆盖文件配置, 保存后按 mail_rev 自动失效已解析实例
 */
class Admin extends Authorization {

    /** 支持的发信驱动 (与 MailManager::createSymfonyTransport 支持的 transport 一致) */
    private const TRANSPORTS = [
        'smtp' => 'SMTP',
        'sendmail' => 'Sendmail',
        'log' => '日志 (调试)',
        'array' => 'Array (不实际发送)',
        'ses' => 'Amazon SES',
        'ses-v2' => 'Amazon SES V2',
        'postmark' => 'Postmark',
        'resend' => 'Resend',
        'mailgun' => 'Mailgun',
        'failover' => '故障转移',
        'roundrobin' => '轮询',
    ];

    /**
     * 读取当前邮件配置 (文件配置 + 后台覆盖; 密码只回传是否已配置)
     */
    public function config() {

        $file = $this->config->get('mail@config', []);
        $smtp = $file['mailers']['smtp'] ?? [];

        show_json([
            'code' => 200,
            'data' => [
                'default' => (string) ($this->sysOverride('mail_mailer') ?: ($file['default'] ?? 'log')),
                'transports' => array_map(
                    fn (string $value, string $label) => ['value' => $value, 'label' => $label],
                    array_keys(self::TRANSPORTS),
                    self::TRANSPORTS
                ),
                'from' => [
                    'address' => (string) ($this->sysOverride('mail_from_address') ?: ($file['from']['address'] ?? '')),
                    'name' => (string) ($this->sysOverride('mail_from_name') ?: ($file['from']['name'] ?? '')),
                ],
                'smtp' => [
                    'host' => (string) ($this->sysOverride('mail_smtp_host') ?: ($smtp['host'] ?? '')),
                    'port' => (int) ($this->sysOverride('mail_smtp_port') ?: ($smtp['port'] ?? 465)),
                    'encryption' => (string) ($this->sysOverride('mail_smtp_encryption') ?: ($smtp['encryption'] ?? 'tls')),
                    'username' => (string) ($this->sysOverride('mail_smtp_username') ?: ($smtp['username'] ?? '')),
                    'has_password' => (bool) ($this->sysOverride('mail_smtp_password') || !empty($smtp['password'])),
                ],
                'sendmail_path' => (string) ($this->sysOverride('mail_sendmail_path') ?: ($file['mailers']['sendmail']['path'] ?? '/usr/sbin/sendmail -bs -i')),
                'log_channel' => (string) ($file['log']['name'] ?? 'mail'),
                'user_email' => (string) ($this->request->getAttribute('admin')['email'] ?? ''),
            ],
        ]);
    }

    /**
     * 保存邮件配置 (写入 config 表并刷新 config 缓存; mail_rev 递增使全部 worker 即刻生效)
     * @log
     */
    public function save() {

        $mailer = input('post.mailer', '', 'trim');
        $fromAddress = input('post.from_address', '', 'trim');
        $fromName = input('post.from_name', '', 'trim');
        $host = input('post.host', '', 'trim');
        $port = (int) input('post.port', 0, 'intval');
        $encryption = input('post.encryption', '', 'trim');
        $username = input('post.username', '', 'trim');
        $password = (string) input('post.password', '');
        $sendmailPath = input('post.sendmail_path', '', 'trim');

        if (!isset(self::TRANSPORTS[$mailer])) {
            show_json(['code' => 201, 'message' => '请选择有效的发信方式']);
            return;
        }

        if ($fromAddress !== '' && !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            show_json(['code' => 201, 'message' => '发件人邮箱格式不正确']);
            return;
        }

        if ($mailer === 'smtp') {
            if ($host === '') {
                show_json(['code' => 201, 'message' => 'SMTP 模式需要填写服务器地址']);
                return;
            }
            if ($port <= 0 || $port > 65535) {
                show_json(['code' => 201, 'message' => 'SMTP 端口无效']);
                return;
            }
        }

        $values = [
            'mail_mailer' => $mailer,
            'mail_from_address' => $fromAddress,
            'mail_from_name' => $fromName,
            'mail_smtp_host' => $host,
            'mail_smtp_port' => $port > 0 ? (string) $port : '',
            'mail_smtp_encryption' => in_array($encryption, ['', 'tls', 'ssl', 'none'], true) ? $encryption : '',
            'mail_smtp_username' => $username,
            // 密码留空表示保持不变
            'mail_smtp_password' => $password,
            'mail_sendmail_path' => $sendmailPath,
            // 配置版本号: 其他 worker 解析 mailer 时检测到变化即自动重新加载
            'mail_rev' => (string) ((int) ($this->sysOverride('mail_rev') ?? 0) + 1),
        ];

        $insert = [];
        foreach ($values as $name => $value) {
            $insert[] = ['name' => $name, 'value' => $value];
        }
        db('config')->upsert($insert, 'name', ['value']);
        $this->refreshConfigCache();

        show_json([
            'code' => 200,
            'message' => '邮件配置已保存并生效, 可发送测试邮件验证',
        ]);
    }

    /**
     * 发送测试邮件 (使用当前生效配置; log 驱动写入 runtime/logs 邮件日志)
     * @log
     */
    public function send() {

        $to = input('post.to', '', 'trim');
        $subject = input('post.subject', '', 'trim') ?: 'X-PHP 邮件服务测试';
        $content = (string) input('post.content', '') ?: '<p>这是一封来自 X-PHP 后台的测试邮件, 收到即说明发信通道工作正常。</p>';

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            show_json(['code' => 201, 'message' => '请输入有效的收件邮箱']);
            return;
        }

        try {
            $manager = $this->container->get(MailManager::class);
            $driver = $manager->getDefaultDriver();

            /** @var Mailer $mailer */
            $mailer = $this->container->get(Mailer::class);
            $mailer->html($content, function ($m) use ($to, $subject) {
                $m->to($to)->subject($subject);
            });

            show_json([
                'code' => 200,
                'message' => "测试邮件已交由 {$driver} 通道发送" . ($driver === 'log' ? ' (log 驱动仅写入日志)' : ''),
                'data' => ['driver' => $driver],
            ]);
        } catch (Throwable $e) {
            isJsonException($e);
            show_json([
                'code' => 201,
                'message' => '发送失败: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 读取后台覆盖值 (config 缓存)
     */
    private function sysOverride(string $key): mixed {
        try {
            $value = $this->config->sysget('config.' . $key);
        } catch (\Throwable) {
            return null;
        }
        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * 从 config 表全量重建 config 缓存 (与系统设置保存逻辑一致)
     */
    private function refreshConfigCache(): void {

        $list = db('config')->select(['name', 'value'])->get();
        $config = [];
        foreach ($list as $value) {
            if (is_string($value->value) && json_validate($value->value)) {
                $value->value = json_decode($value->value, true);
            }
            data_set($config, $value->name, $value->value);
        }
        cache_write('config', $config);
    }
}
