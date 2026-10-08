<?php

declare(strict_types=1);


namespace App\queue;

use xphp\Contract\CompressInterface;
use xphp\Contract\UnCompressInterface;

class JobMessage implements MessageInterface {

    protected int $attempts = 0;

    protected string $uid;

    public function __construct(protected JobInterface $job) {
        $this->uid = bin2hex(random_bytes(8));
    }

    public function __serialize(): array {
        $job = $this->job;
        if ($job instanceof CompressInterface) {
            /* @phpstan-ignore-next-line */
            $job = $job->compress();
        }

        return [
            $job,  // Compatible with old version, will be removed at v3.2
            $this->attempts,  // Compatible with old version, will be removed at v3.2
            'job' => $job,
            'attempts' => $this->attempts,
            'uid' => $this->uid,
        ];
    }

    public function __unserialize(array $data): void {
        if (array_is_list($data)) { // Compatible with old version, will be removed at v3.2
            $data = [
                'job' => $data[0],
                'attempts' => $data[1],
            ];
        }

        $job = $data['job'];

        if ($job instanceof UnCompressInterface) {
            $job = $job->uncompress();
        }

        $this->job = $job;
        $this->attempts = $data['attempts'];
        // 兼容升级前已入队的旧消息(无 uid)
        $this->uid = (string) ($data['uid'] ?? '') ?: bin2hex(random_bytes(8));
    }

    public function getUid(): string {
        if (! isset($this->uid) || $this->uid === '') {
            $this->uid = bin2hex(random_bytes(8));
        }

        return $this->uid;
    }

    public function job(): JobInterface {
        return $this->job;
    }

    public function attempts(): bool {
        if ($this->job->getMaxAttempts() > $this->attempts++) {
            return true;
        }
        return false;
    }

    public function getAttempts(): int {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): void {
        $this->attempts = $attempts;
    }
}
