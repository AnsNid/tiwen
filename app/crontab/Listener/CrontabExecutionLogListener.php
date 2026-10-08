<?php

declare(strict_types=1);

namespace App\crontab\Listener;

use App\crontab\Crontab;
use App\crontab\Event\AfterExecute;
use App\crontab\Event\BeforeExecute;
use App\crontab\Event\FailToExecute;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use WeakMap;
use xphp\Event\Contract\ListenerInterface;

class CrontabExecutionLogListener implements ListenerInterface {
    /** @var WeakMap<Crontab, float> 以任务对象为键记录开始时间,对象释放后自动清理,避免 spl_object_id 复用串号与泄漏 */
    private static ?WeakMap $startTimes = null;

    /** @var array<string, Logger> */
    private static array $loggers = [];

    public function listen(): array {
        return [
            BeforeExecute::class,
            AfterExecute::class,
            FailToExecute::class,
        ];
    }

    public function process(object $event): void {
        $crontab = $event->crontab;
        $name = $crontab->getName() ?: 'default';
        $logger = $this->getLogger($name);
        $startTimes = self::$startTimes ??= new WeakMap();

        if ($event instanceof BeforeExecute) {
            $startTimes[$crontab] = microtime(true);
            $logger->info(sprintf(
                'Task started | type=%s | rule=%s',
                $crontab->getType(),
                $crontab->getRule()
            ));
            return;
        }

        $start = $startTimes[$crontab] ?? microtime(true);
        unset($startTimes[$crontab]);
        $duration = round(microtime(true) - $start, 3);

        if ($event instanceof AfterExecute) {
            $result = $event->result;
            $resultStr = is_scalar($result) ? (string) $result : json_encode($result, JSON_UNESCAPED_UNICODE);
            $logger->info(sprintf('Task success | duration=%ss | result=%s', $duration, $resultStr ?? 'null'));
            return;
        }

        if ($event instanceof FailToExecute) {
            $throwable = $event->getThrowable();
            $logger->error(sprintf(
                'Task failed | duration=%ss | error=%s | trace=%s:%d',
                $duration,
                $throwable->getMessage(),
                $throwable->getFile(),
                $throwable->getLine()
            ));
        }
    }

    private function getLogger(string $name): Logger {
        if (!isset(self::$loggers[$name])) {
            $dir = runtime_path('logs/crontab');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$loggers[$name] = logger($name, [
                'handler' => [
                    'class' => StreamHandler::class,
                    'constructor' => [
                        'stream' => $dir . $name . '.log',
                        'level' => Level::Debug,
                    ],
                ],
            ]);
        }
        return self::$loggers[$name];
    }
}
