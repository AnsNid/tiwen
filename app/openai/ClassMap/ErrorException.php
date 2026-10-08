<?php

declare(strict_types=1);

namespace OpenAI\Exceptions;

use Exception;
use Psr\Http\Message\ResponseInterface;

/**
 * 覆盖 SDK 原生 ErrorException：部分上游/代理（如 grsai）在 4xx/5xx
 * 时直接返回 {"error": "字符串消息"}，而原生构造函数仅接受 array，
 * 会触发 TypeError。这里放宽为 string|array 兜底，保持对外 API 一致。
 */

final class ErrorException extends Exception {

    private readonly int $statusCode;

    /**
     * @param array{message?: string|array<int, string>, type?: ?string, code?: string|int|null}|string $contents
     */
    public function __construct(private readonly string|array $contents, public readonly ResponseInterface $response) {
        $this->statusCode = $response->getStatusCode();

        $contents = is_string($contents) ? ['message' => $contents] : $contents;
        $message = ($contents['message'] ?? null) ?: (string) ($contents['code'] ?? null) ?: 'Unknown error';

        if (is_array($message)) {
            $message = implode(PHP_EOL, $message);
        }

        parent::__construct($message);
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }

    public function getErrorMessage(): string {
        return $this->getMessage();
    }

    public function getErrorType(): ?string {
        return is_array($this->contents) ? ($this->contents['type'] ?? null) : null;
    }

    public function getErrorCode(): string|int|null {
        return is_array($this->contents) ? ($this->contents['code'] ?? null) : null;
    }
}
