<?php

declare(strict_types=1);


namespace App\queue;

use xphp\Contract\CompressInterface;
use xphp\Contract\UnCompressInterface;
use Throwable;

abstract class Job implements JobInterface, CompressInterface, UnCompressInterface {

    protected int $maxAttempts = 0;

    /**
     * 单次执行的超时预算(秒), null = 使用队列池配置的 handle_timeout.
     * 长耗时任务(文件同步/下载等)应覆盖此值, 避免执行中被超时回收重复入队.
     */
    protected ?int $handleTimeout = null;

    public function fail(Throwable $e): void {
    }

    public function setMaxAttempts(int $maxAttempts): static {
        $this->maxAttempts = $maxAttempts;

        return $this;
    }

    public function getMaxAttempts(): int {
        return $this->maxAttempts;
    }

    public function setHandleTimeout(?int $handleTimeout): static {
        $this->handleTimeout = $handleTimeout;

        return $this;
    }

    public function getHandleTimeout(): ?int {
        return $this->handleTimeout;
    }

    public function uncompress(): static {
        foreach ($this as $key => $value) {
            if ($value instanceof UnCompressInterface) {
                $this->{$key} = $value->uncompress();
            }
        }

        return $this;
    }

    public function compress(): static {
        foreach ($this as $key => $value) {
            if ($value instanceof CompressInterface) {
                $this->{$key} = $value->compress();
            }
        }

        return $this;
    }
}
