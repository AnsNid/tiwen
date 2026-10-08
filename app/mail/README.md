
## 快速上手（中文）

> 本模块是邮件**发送 SDK**（基于 Symfony Mailer），只负责把邮件发出去。
> 它本身**不含**验证码、定时任务、数据库表、Controller/API。验证码、定时提醒等业务逻辑请放在业务模块里，通过 `app('mail')` 调用本模块发送。

### 1. 启用模块

在 `config/apps.php` 加入：

```php
'mail' => 1,
```

### 2. 配置 `.env`

默认 `MAIL_MAILER=log`，邮件会写入 `runtime/logs`，便于先跑通链路。拿到真实 SMTP 后改成 `MAIL_MAILER=smtp` 并填好 `MAIL_HOST/MAIL_PORT/MAIL_USERNAME/MAIL_PASSWORD`：

```env
MAIL_MAILER=log              # 调试用 log; 生产用 smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=465
MAIL_ENCRYPTION=tls
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME=XPHP
```

> 改完代码或配置记得 `rm -rf runtime/container` 清 DI 缓存。

### 3. 最小调用示例

> ⚠️ 本模块是 SDK,**没有 Api.php**,不能用 `app('mail')` —— `app('mail')` 会去找不存在的 `App\mail\Api` 类而失败。
> 正确做法:从容器取 Mailer 接口,或用 Facade。

```php
// 写法 1: 从容器取 Mailer(推荐,最简洁,适合验证码/通知)
$mailer = xphp(\App\mail\Contract\Mailer::class);

// 快捷发送纯文本
$mailer->raw('您的验证码是 1234,5 分钟内有效', function ($m) {
    $m->to('user@example.com')->subject('验证码');
});

// 发 HTML
$mailer->html('<h1>hello</h1>', function ($m) {
    $m->to('user@example.com')->subject('测试');
});

// 写法 2: Facade(配合 Mailable 类,适合模板/附件/Markdown 正式邮件)
\App\mail\Facade\Mail::to('user@example.com')->send(new \App\Mail\TestMail($user));
```

### 4. 后续扩展（本模块不提供，需业务层实现）

- **邮件验证码**：可参考克隆 `app/sms/`（短信验证码）的结构，把 `mobile` 换成 `email`、发送通道换成 `app('mail')`。
- **定时提醒**：用 `app/crontab` 注册定时任务 + `app/queue` 的 `queue()` 异步投递发送。

---

## Usage

###

```php
// config/autoload/mail.php
use function xphp\Support\env;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL','smtp://xxx@xxx:xxx@xxx.com:465'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'group' => env('MAIL_LOG_GROUP','default'),
            'name' => env('MAIL_LOG_NAME','mail'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'xphp@xphp.com'),
        'name' => env('MAIL_FROM_NAME', 'xphp'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Markdown Mail Settings
    |--------------------------------------------------------------------------
    |
    | If you are using Markdown based email rendering, you may configure your
    | theme and component paths here, allowing you to customize the design
    | of the emails. Or, you may simply stick with the Laravel defaults!
    |
    */

    'markdown' => [
        'theme' => env('MAIL_MARKDOWN_THEME', 'default'),
        'paths' => [
            BASE_PATH . '/storage/views/mail',
        ],
    ],
];
```

### Build a Mail Class

```shell
php index.php gen:mail TestMail
```

```php
// app/Mail/TestMail.php

namespace App\Mail;

use App\mail\Mailable;
use App\mail\Mailable\Content;
use App\mail\Mailable\Envelope;

class TestMail extends Mailable
{

    /**
     * Create a new message instance.
     */
    public function __construct(
        private readonly string $name,
    ){}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Test Mail',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.test',
            with: [
                'name' => $this->name,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \App\mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
```

### Your Controller or Service

```php
// app/Controller/IndexController.php

use App\mail\Facade\Mail;

class IndexController extends AbstractController
{
    public function index()
    {
        $user = $this->request->input('user', 'xphp');
        $mailer = Mail::mailer('smtp');
        $mailer->alwaysFrom('root@imoi.cn','xphp');

        $mailer->to('2771717608@qq.com')->send(new \App\Mail\TestMail($user));
        $method = $this->request->getMethod();

        return [
            'method' => $method,
            'message' => "Hello {$user}.",
        ];
    }
}

```
