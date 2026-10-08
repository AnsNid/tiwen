<?php

declare(strict_types=1);

namespace App\crontab\Service;

use App\crontab\Annotation\Crontab as CrontabAnnotation;
use App\crontab\Crontab;
use App\crontab\LoggerInterface;
use xphp\Context\ApplicationContext;
use xphp\Contract\StdoutLoggerInterface;
use xphp\Di\Annotation\AnnotationCollector;
use xphp\Di\ReflectionManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as PsrLoggerInterface;
use ReflectionException;

/**
 * 注解(#[Crontab])任务来源:收集、构建与列表,供注册监听器与后台 API 共用
 */
class AnnotationTaskService {

    protected ?PsrLoggerInterface $logger = null;

    public function __construct(protected ContainerInterface $container) {
        $this->logger = match (true) {
            $container->has(LoggerInterface::class) => $container->get(LoggerInterface::class),
            $container->has(StdoutLoggerInterface::class) => $container->get(StdoutLoggerInterface::class),
            default => null,
        };
    }

    /**
     * 类级 + 方法级注解任务统一构建为 Crontab 对象
     * @return Crontab[]
     */
    public function getCrontabs(): array {
        $annotations = array_merge(
            AnnotationCollector::getClassesByAnnotation(CrontabAnnotation::class),
            $this->getMethodAnnotations()
        );

        $crontabs = [];
        foreach ($annotations as $annotation) {
            if ($annotation instanceof CrontabAnnotation) {
                $crontabs[] = $this->buildCrontabByAnnotation($annotation);
            }
        }
        return $crontabs;
    }

    /**
     * 注解任务名集合 name => true
     * @return array<string, true>
     */
    public function getNames(): array {
        $names = [];
        foreach ($this->getCrontabs() as $crontab) {
            $name = $crontab->getName();
            if ($name !== null && $name !== '') {
                $names[$name] = true;
            }
        }
        return $names;
    }

    /**
     * 后台列表用:规范化为与 crontab_task 行同构的字段
     * @param array $databaseNames 数据库任务名集合 name => true,用于标记是否已被同名行接管
     */
    public function list(array $databaseNames = []): array {
        $list = [];
        foreach ($this->getCrontabs() as $crontab) {
            $callback = $crontab->getCallback();
            $timezone = $crontab->getTimezone();
            $list[] = [
                'id' => 0,
                'name' => $crontab->getName(),
                'type' => $crontab->getType(),
                'rule' => $crontab->getRule(),
                'callback' => match (true) {
                    is_array($callback) => json_encode($callback, JSON_UNESCAPED_SLASHES),
                    is_object($callback) => '',
                    default => (string) $callback,
                },
                'singleton' => $crontab->isSingleton() ? 1 : 0,
                'mutex_pool' => $crontab->getMutexPool(),
                'mutex_expires' => $crontab->getMutexExpires(),
                'on_one_server' => $crontab->isOnOneServer() ? 1 : 0,
                'memo' => (string) ($crontab->getMemo() ?? ''),
                'timezone' => $timezone instanceof \DateTimeZone ? $timezone->getName() : (string) ($timezone ?? ''),
                'environments' => implode(',', $crontab->getEnvironments()),
                // 有效状态:注解 enable(含 enable 方法求值)为真即随代码运行
                'status' => $crontab->isEnable() ? 1 : 0,
                'source' => 'annotation',
                'origin' => is_array($callback) ? implode('::', $callback) : '',
                'overridden' => isset($databaseNames[(string) $crontab->getName()]),
            ];
        }
        return $list;
    }

    /**
     * 按任务名查找注解任务
     */
    public function findByName(string $name): ?Crontab {
        foreach ($this->getCrontabs() as $crontab) {
            if ($crontab->getName() === $name) {
                return $crontab;
            }
        }
        return null;
    }

    private function getMethodAnnotations(): array {
        $result = AnnotationCollector::getMethodsByAnnotation(CrontabAnnotation::class);
        $annotations = [];
        foreach ($result as $item) {
            $annotations[] = $item['annotation'];
        }
        return $annotations;
    }

    private function buildCrontabByAnnotation(CrontabAnnotation $annotation): Crontab {
        $crontab = new Crontab();
        isset($annotation->name) && $crontab->setName($annotation->name);
        isset($annotation->type) && $crontab->setType($annotation->type);
        isset($annotation->rule) && $crontab->setRule($annotation->rule);
        isset($annotation->singleton) && $crontab->setSingleton($annotation->singleton);
        isset($annotation->mutexPool) && $crontab->setMutexPool($annotation->mutexPool);
        isset($annotation->mutexExpires) && $crontab->setMutexExpires($annotation->mutexExpires);
        isset($annotation->onOneServer) && $crontab->setOnOneServer($annotation->onOneServer);
        isset($annotation->callback) && $crontab->setCallback($annotation->callback);
        isset($annotation->memo) && $crontab->setMemo($annotation->memo);
        isset($annotation->enable) && $crontab->setEnable($this->resolveCrontabEnableMethod($annotation->enable));
        isset($annotation->timezone) && $crontab->setTimezone($annotation->timezone);
        isset($annotation->environments) && $crontab->setEnvironments($annotation->environments);
        isset($annotation->options) && $crontab->setOptions($annotation->options);

        return $crontab;
    }

    private function resolveCrontabEnableMethod(array|bool $enable): bool {
        if (is_bool($enable)) {
            return $enable;
        }

        $className = reset($enable);
        $method = end($enable);

        try {
            $reflectionClass = ReflectionManager::reflectClass($className);
            $reflectionMethod = $reflectionClass->getMethod($method);

            if ($reflectionMethod->isPublic()) {
                if ($reflectionMethod->isStatic()) {
                    return $className::$method();
                }

                $container = ApplicationContext::getContainer();
                if ($container->has($className)) {
                    return $container->get($className)->{$method}();
                }
            }

            $this->logger?->info('Crontab enable method is not public, skip register.');
        } catch (ReflectionException $e) {
            $this->logger?->error('Resolve crontab enable failed, skip register.' . $e);
        }

        return false;
    }
}
