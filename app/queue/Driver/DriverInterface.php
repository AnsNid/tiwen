<?php

declare(strict_types=1);


namespace App\queue\Driver;

use App\queue\JobInterface;

interface DriverInterface {
    /**
     * Push a job to queue.
     */
    public function push(JobInterface $job, int $delay = 0): bool;

    /**
     * Push multi jobs to queue.
     */
    public function pushAll(array $jobs, int $delay = 0): bool;

    /**
     * Delete a delay job to queue.
     */
    public function delete(JobInterface $job): bool;

    /**
     * Pop a job from queue.
     */
    public function pop(): array;

    /**
     * Ack a job.
     */
    public function ack(mixed $data): bool;

    /**
     * Push a job to failed queue.
     */
    public function fail(mixed $data): bool;

    /**
     * Consume jobs from a queue.
     */
    public function consume(): void;

    /**
     * Reload failed message into waiting queue.
     */
    public function reload(?string $queue = null): int;

    /**
     * Delete all failed message from failed queue.
     */
    public function flush(?string $queue = null): bool;

    /**
     * Return info for current queue.
     */
    public function info(): array;

    /**
     * Get detailed information about jobs in a specific queue.
     * @param string $queueType Queue type: failed, timeout, waiting, delayed, reserved
     * @param int $start Starting position
     * @param int $limit Number of jobs to retrieve
     * @return array
     */
    public function getQueueDetails(string $queueType, int $start = 0, int $limit = 10): array;

    /**
     * Get the total count of jobs in a specific queue.
     * @param string $queueType Queue type: failed, timeout, waiting, delayed, reserved
     */
    public function getQueueCount(string $queueType): int;

    /**
     * The channel name of this driver (used by trace/observability keys).
     */
    public function getQueueChannel(): string;
}
