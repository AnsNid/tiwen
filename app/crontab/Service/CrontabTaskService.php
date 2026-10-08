<?php

declare(strict_types=1);

namespace App\crontab\Service;

use App\crontab\Crontab;
use xphp\Process\ProcessCollector;

class CrontabTaskService {

    /**
     * Get all enabled crontab tasks as Crontab objects.
     * @return Crontab[]
     */
    public function getAllEnabled(): array {
        $rows = m('crontab.CrontabTask')->where('status', 1)->get()->toArray();
        $crontabs = [];
        foreach ($rows as $row) {
            $crontab = $this->rowToCrontab($row);
            if ($crontab) {
                $crontabs[] = $crontab;
            }
        }
        return $crontabs;
    }

    /**
     * All task names regardless of status, as a name => true map.
     * 用于同名覆盖判定:数据库存在同名行(无论启停)即接管代码/注解来源的任务
     * @return array<string, true>
     */
    public function getAllNames(): array {
        $rows = m('crontab.CrontabTask')->where('status', 'in', [0, 1])->get()->toArray();
        $names = [];
        foreach ($rows as $row) {
            $names[$row['name']] = true;
        }
        return $names;
    }

    /**
     * Send reload signal to the crontab dispatcher processes.
     */
    public function sendReloadSignal(): void {
        try {
            $processes = ProcessCollector::get('crontab-dispatcher');
            if (!empty($processes)) {
                // 广播到全部 dispatcher 进程,避免多进程部署时部分进程配置不刷新
                $data = serialize(['action' => 'reload']);
                foreach ($processes as $process) {
                    $process->exportSocket()->send($data);
                }
            }
        } catch (\Throwable $e) {
            logger()?->error('Failed to send crontab reload signal: ' . $e->getMessage());
        }
    }

    /**
     * Convert a database row to a Crontab object.
     */
    public function rowToCrontab(array $row): ?Crontab {
        try {
            $crontab = new Crontab();
            $crontab->setName($row['name'])
                ->setRule($row['rule'])
                ->setType($row['type'])
                ->setMemo($row['memo'] ?? '')
                ->setEnable(true);

            if ($row['type'] === 'callback') {
                $callback = json_decode($row['callback'], true);
                if (!is_array($callback) || count($callback) < 2) {
                    return null;
                }
                $crontab->setCallback($callback);
            } elseif ($row['type'] === 'command') {
                $crontab->setCallback($row['callback']);
            }

            if (!empty($row['singleton'])) {
                $crontab->setSingleton(true);
            }
            if (!empty($row['mutex_pool'])) {
                $crontab->setMutexPool($row['mutex_pool']);
            }
            if (!empty($row['mutex_expires'])) {
                $crontab->setMutexExpires((int) $row['mutex_expires']);
            }
            if (!empty($row['on_one_server'])) {
                $crontab->setOnOneServer(true);
            }
            if (!empty($row['timezone'])) {
                $crontab->setTimezone($row['timezone']);
            }
            if (!empty($row['environments'])) {
                $crontab->setEnvironments(explode(',', $row['environments']));
            }
            if (!empty($row['options'])) {
                $crontab->setOptions(json_decode($row['options'], true) ?: []);
            }

            return $crontab;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
