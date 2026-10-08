<?php

declare(strict_types=1);


namespace App\queue\Driver;

use App\queue\Exception\InvalidDriverException;
use xphp\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

use function xphp\Support\make;

class DriverFactory {
    /**
     * @var DriverInterface[]
     */
    protected array $drivers = [];

    protected array $configs = [];

    /**
     * @throws InvalidDriverException when the driver class not exist or the class is not implemented DriverInterface
     */
    public function __construct(protected ContainerInterface $container) {
        $config = $container->get(ConfigInterface::class);

        $this->configs = $config->get('queue@config', []);

        foreach ($this->configs as $key => $item) {
            $driverClass = $item['driver'];

            if (! class_exists($driverClass)) {
                throw new InvalidDriverException(sprintf('[Error] class %s is invalid.', $driverClass));
            }

            $driver = make($driverClass, ['config' => $item]);
            if (! $driver instanceof DriverInterface) {
                throw new InvalidDriverException(sprintf('[Error] class %s is not instanceof %s.', $driverClass, DriverInterface::class));
            }

            $this->drivers[$key] = $driver;
        }
    }

    public function __get($name): DriverInterface {
        return $this->get($name);
    }

    /**
     * @throws InvalidDriverException when the driver invalid
     */
    public function get(string $name): DriverInterface {
        $driver = $this->drivers[$name] ?? null;
        if (! $driver instanceof DriverInterface) {
            throw new InvalidDriverException(sprintf('[Error]  %s is a invalid driver.', $name));
        }
        return $driver;
    }

    public function getConfig($name): array {
        return $this->configs[$name] ?? [];
    }
}
