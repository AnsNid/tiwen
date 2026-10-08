<?php

declare(strict_types=1);

namespace App\tiwen\Exception;

use Psr\Log\LoggerInterface;
use xphp\ExceptionHandler\ExceptionHandler;
use xphp\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use xphp\HttpMessage\Exception\NotFoundHttpException;
use Throwable;

class TiwenExceptionHandler extends ExceptionHandler {

    public function __construct(protected LoggerInterface $logger) {
    }

    public function handle(Throwable $throwable, ResponseInterface $response) {
        $params = request()->getServerParameters();
        $this->logger->error($throwable->getMessage(), [
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'query' => $params['query_string'] ?? '',
            'method' => $params['request_method'] ?? '',
            'request_uri' => $params['request_uri'] ?? '',
            'server_port' => $params['server_port'] ?? '',
            'remote_addr' => $params['remote_addr'] ?? '',
            'request_time' => $params['request_time'] ?? '',
        ]);
        $content = json_encode([
            'code' => 500,
            'message' => '服务器内部错误',
            'data' => [
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'message' => $throwable->getMessage(),
            ]
        ], 128 | 256);

        xphp(\xphp\Contract\StdoutLoggerInterface::class)->error($throwable->getFile() . ':' . $throwable->getLine() . ' ' . $throwable->getMessage());
        xphp(\xphp\Contract\StdoutLoggerInterface::class)->error($throwable->getTraceAsString());

        return $response
            ->withHeader('Server', 'XPHP')
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus(500)
            ->withBody(new SwooleStream($content));
    }

    public function isValid(Throwable $throwable): bool {
        return true;
    }
}
