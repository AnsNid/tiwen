<?php

declare(strict_types=1);

namespace App\mail;

use Aws\Ses\SesClient;
use Aws\SesV2\SesV2Client;
use Closure;
use App\mail\Contract\Factory;
use App\mail\Transport\ArrayTransport;
use App\mail\Transport\LogTransport;
use App\mail\Transport\SesTransport;
use App\mail\Transport\SesV2Transport;
use xphp\Support\ConfigurationUrlParser;
use xphp\Collection\Arr;
use xphp\Contract\ConfigInterface;
use xphp\Logger\LoggerFactory;
use xphp\Stringable\Str;
use xphp\ViewEngine\Contract\FactoryInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunTransportFactory;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function xphp\Tappable\tap;

class MailManager implements Factory {
    /**
     * The array of resolved mailers.
     * @var Mailer[]
     */
    protected array $mailers = [];

    /**
     * The registered custom driver creators.
     */
    protected array $customCreators = [];

    /**
     * 已解析 mailer 时读取到的后台配置版本号 (mail_rev), 变更即失效全部已解析实例
     */
    protected ?string $overrideRev = null;

    public function __construct(protected ContainerInterface $container, protected ConfigInterface $config) {
    }

    /**
     * Dynamically call the default driver instance.
     * @param mixed $method
     * @param mixed $parameters
     */
    public function __call($method, $parameters) {
        return $this->mailer()->{$method}(...$parameters);
    }

    /**
     * Get a mailer instance by name.
     */
    public function mailer(?string $name = null): Mailer {
        $name = $name ?: $this->getDefaultDriver();
        return $this->mailers[$name] = $this->get($name);
    }

    /**
     * Get a mailer driver instance.
     */
    public function driver(?string $driver = null): Mailer {
        return $this->mailer($driver);
    }

    /**
     * Create a new transport instance.
     */
    public function createSymfonyTransport(array $config): TransportInterface {
        $transport = $config['transport'] ?? '';

        if (isset($this->customCreators[$transport])) {
            return call_user_func($this->customCreators[$transport], $config);
        }

        if (trim($transport) === '' || ! method_exists($this, $method = 'create' . ucfirst(Str::camel($transport)) . 'Transport')) {
            throw new InvalidArgumentException("Unsupported mail transport [{$transport}].");
        }

        return $this->{$method}($config);
    }

    /**
     * Get the default mail driver name.
     * 后台保存的 mail_mailer 覆盖文件配置
     */
    public function getDefaultDriver(): string {
        $override = $this->sysOverride('mail_mailer');
        return (string) ($override ?: $this->config->get('mail@config.default'));
    }

    /**
     * Set the default mail driver name.
     */
    public function setDefaultDriver(string $name): void {
        $this->config->set('mail@config.default', $name);
    }

    /**
     * Disconnect the given mailer and remove from local cache.
     */
    public function purge(?string $name = null): void {
        $name = $name ?: $this->getDefaultDriver();

        unset($this->mailers[$name]);
    }

    /**
     * Register a custom transport creator Closure.
     */
    public function extend(string $driver, Closure $callback): static {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Forget all of the resolved mailer instances.
     */
    public function forgetMailers(): static {
        $this->mailers = [];

        return $this;
    }

    /**
     * Attempt to get the mailer from the local cache.
     */
    protected function get(string $name): Mailer {
        // 后台保存配置后 mail_rev 递增, 丢弃全部已解析实例, 使新配置即刻生效
        $rev = (string) ($this->sysOverride('mail_rev') ?? '');
        if ($rev !== $this->overrideRev) {
            $this->mailers = [];
            $this->overrideRev = $rev;
        }

        return $this->mailers[$name] ?? $this->resolve($name);
    }

    /**
     * 读取后台保存的邮件配置覆盖 (config 表 mail_* 键, 经 config 缓存文件全 worker 共享)
     */
    protected function sysOverride(string $key): mixed {
        try {
            $value = $this->config->sysget('config.' . $key);
        } catch (\Throwable) {
            return null;
        }
        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * Resolve the given mailer.
     */
    protected function resolve(string $name): Mailer {

        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Mailer [{$name}] is not defined.");
        }

        // 后台保存的全局发件人覆盖文件配置
        $fromAddress = $this->sysOverride('mail_from_address');
        if ($fromAddress) {
            $config['from'] = [
                'address' => $fromAddress,
                'name' => (string) ($this->sysOverride('mail_from_name') ?? ''),
            ];
        }

        // Once we have created the mailer instance we will set a container instance
        // on the mailer. This allows us to resolve mailer classes via containers
        // for maximum testability on said classes instead of passing Closures.
        $mailer = new Mailer(
            $name,
            $this->container->get(FactoryInterface::class),
            $this->createSymfonyTransport($config),
            $this->container->get(EventDispatcherInterface::class)
        );

        // Next we will set all of the global addresses on this mailer, which allows
        // for easy unification of all "from" addresses as well as easy debugging
        // of sent messages since these will be sent to a single email address.
        foreach (['from', 'reply_to', 'to', 'return_path'] as $type) {
            $this->setGlobalAddress($mailer, $config, $type);
        }

        return $mailer;
    }

    /**
     * Create an instance of the Symfony SMTP Transport driver.
     */
    protected function createSmtpTransport(array $config): EsmtpTransport {
        $factory = new EsmtpTransportFactory();
        $scheme = $config['scheme'] ?? null;

        if (! $scheme) {
            $scheme = ! empty($config['encryption']) && $config['encryption'] === 'tls'
                ? (($config['port'] == 465) ? 'smtps' : 'smtp')
                : '';
        }

        /** @var EsmtpTransport $transport */
        $transport = $factory->create(new Dsn(
            $scheme,
            (string) $config['host'],
            isset($config['username']) ? ((string) $config['username']) : null,
            isset($config['password']) ? ((string) $config['password']) : null,
            isset($config['port']) ? ((int) $config['port']) : null,
            $config
        ));

        return $this->configureSmtpTransport($transport, $config);
    }

    /**
     * Configure the additional SMTP driver options.
     */
    protected function configureSmtpTransport(EsmtpTransport $transport, array $config): EsmtpTransport {
        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            if (isset($config['source_ip'])) {
                $stream->setSourceIp($config['source_ip']);
            }

            if (isset($config['timeout'])) {
                $stream->setTimeout($config['timeout']);
            }
        }

        return $transport;
    }

    /**
     * Create an instance of the Symfony Sendmail Transport driver.
     */
    protected function createSendmailTransport(array $config): SendmailTransport {
        return new SendmailTransport(
            $config['path'] ?? $this->config->get('mail@config.sendmail')
        );
    }

    /**
     * Create an instance of the Symfony Amazon SES Transport driver.
     */
    protected function createSesTransport(array $config): SesTransport {
        $config = array_merge(
            $this->config->get('services.ses', []),
            ['version' => 'latest', 'service' => 'email'],
            $config
        );

        $config = Arr::except($config, ['transport']);

        return new SesTransport(
            new SesClient($this->addSesCredentials($config)),
            $config['options'] ?? []
        );
    }

    /**
     * Create an instance of the Symfony Amazon SES V2 Transport driver.
     */
    protected function createSesV2Transport(array $config): SesV2Transport {
        $config = array_merge(
            $this->config->get('services.ses', []),
            ['version' => 'latest'],
            $config
        );

        $config = Arr::except($config, ['transport']);

        return new SesV2Transport(
            new SesV2Client($this->addSesCredentials($config)),
            $config['options'] ?? []
        );
    }

    /**
     * Add the SES credentials to the configuration array.
     */
    protected function addSesCredentials(array $config): array {
        if (! empty($config['key']) && ! empty($config['secret'])) {
            $config['credentials'] = Arr::only($config, ['key', 'secret', 'token']);
        }

        return Arr::except($config, ['token']);
    }

    /**
     * Create an instance of the Symfony Mail Transport driver.
     */
    protected function createMailTransport(): SendmailTransport {
        return new SendmailTransport();
    }

    /**
     * Create an instance of the Symfony Mailgun Transport driver.
     */
    protected function createMailgunTransport(array $config): TransportInterface {
        $factory = new MailgunTransportFactory(null, $this->getHttpClient($config));

        if (! isset($config['secret'])) {
            $config = $this->config->get('services.mailgun', []);
        }

        return $factory->create(new Dsn(
            'mailgun+' . ($config['scheme'] ?? 'https'),
            $config['endpoint'] ?? 'default',
            $config['secret'],
            $config['domain']
        ));
    }

    /**
     * Create an instance of the Symfony Postmark Transport driver.
     */
    protected function createPostmarkTransport(array $config): PostmarkApiTransport {
        $factory = new PostmarkTransportFactory(null, $this->getHttpClient($config));
        $options = isset($config['message_stream_id'])
            ? ['message_stream' => $config['message_stream_id']]
            : [];
        /** @var PostmarkApiTransport $transport */
        $transport = $factory->create(new Dsn(
            'postmark+api',
            'default',
            $config['token'] ?? $this->config->get('services.postmark.token'),
            null,
            null,
            $options
        ));

        tap($transport); // Nothing to do

        return $transport;
    }

    /**
     * Create an instance of the Symfony Failover Transport driver.
     */
    protected function createFailoverTransport(array $config): FailoverTransport {
        $transports = [];

        foreach ($config['mailers'] as $name) {
            $config = $this->getConfig($name);

            if (is_null($config)) {
                throw new InvalidArgumentException("Mailer [{$name}] is not defined.");
            }

            $transports[] = $this->createSymfonyTransport($config);
        }

        return new FailoverTransport($transports);
    }

    /**
     * Create an instance of the Symfony Roundrobin Transport driver.
     */
    protected function createRoundrobinTransport(array $config): RoundRobinTransport {
        $transports = [];

        foreach ($config['mailers'] as $name) {
            $config = $this->getConfig($name);

            if (is_null($config)) {
                throw new InvalidArgumentException("Mailer [{$name}] is not defined.");
            }

            $transports[] = $this->createSymfonyTransport($config);
        }

        return new RoundRobinTransport($transports);
    }

    /**
     * Create an instance of the Log Transport driver.
     */
    protected function createLogTransport(array $config): LogTransport {
        $loggerFactory = $this->container->get(LoggerFactory::class);

        $logger = $loggerFactory->make(
            $config['name'] ?: $this->config->get('mail@config.log.name'),
            $config['group'] ?: $this->config->get('mail@config.log.group')
        );

        return new LogTransport($logger);
    }

    /**
     * Create an instance of the Array Transport Driver.
     */
    protected function createArrayTransport(): ArrayTransport {
        return new ArrayTransport();
    }

    /**
     * Get a configured Symfony HTTP client instance.
     */
    protected function getHttpClient(array $config): ?HttpClientInterface {
        if ($options = ($config['client'] ?? false)) {
            $maxHostConnections = Arr::pull($options, 'max_host_connections', 6);
            $maxPendingPushes = Arr::pull($options, 'max_pending_pushes', 50);

            return HttpClient::create($options, $maxHostConnections, $maxPendingPushes);
        }

        return null;
    }

    /**
     * Set a global address on the mailer by type.
     */
    protected function setGlobalAddress(Mailer $mailer, array $config, string $type): void {
        $address = Arr::get($config, $type, $this->config->get('mail@config.' . $type));

        if (is_array($address) && isset($address['address'])) {
            $mailer->{'always' . Str::studly($type)}($address['address'], $address['name']);
        }
    }

    /**
     * Get the mail connection configuration.
     */
    protected function getConfig(string $name): ?array {
        $config = $this->config->get("mail@config.mailers.{$name}");

        if (is_array($config)) {
            // 后台保存的 SMTP/发送参数覆盖文件配置 (仅覆盖该 mailer 声明过的字段)
            $overrides = [
                'host' => 'mail_smtp_host',
                'port' => 'mail_smtp_port',
                'encryption' => 'mail_smtp_encryption',
                'username' => 'mail_smtp_username',
                'password' => 'mail_smtp_password',
                'path' => 'mail_sendmail_path',
            ];
            foreach ($overrides as $field => $key) {
                $value = $this->sysOverride($key);
                if ($value !== null && array_key_exists($field, $config)) {
                    $config[$field] = $field === 'port' ? (int) $value : $value;
                }
            }
        }

        if (isset($config['url'])) {
            $config = array_merge($config, (new ConfigurationUrlParser())->parseConfiguration($config));
            $config['transport'] = Arr::pull($config, 'driver');
        }

        return $config;
    }
}
