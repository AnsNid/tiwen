<?php

declare(strict_types=1);

namespace App\openai;

use OpenAI\Client;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Responses\Chat\CreateResponseChoiceAudio;
use OpenAI\Responses\Chat\CreateResponseUsageCompletionTokensDetails;
use OpenAI\Responses\Chat\CreateResponseUsage;
use OpenAI\Responses\Images\EditResponse;
use OpenAI\Responses\Images\ImageResponseUsageInputTokensDetails;

final class ConfigProvider {
    public function __invoke() {
        return [
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                    'class_map' => [
                        CreateResponseUsageCompletionTokensDetails::class => __DIR__ . '/ClassMap/CreateResponseUsageCompletionTokensDetails.php',
                        CreateResponseUsage::class => __DIR__ . '/ClassMap/CreateResponseUsage.php',
                        CreateResponseChoiceAudio::class => __DIR__ . '/ClassMap/CreateResponseChoiceAudio.php',
                        // 兼容部分上游/代理返回的 created 为 null，原版 readonly int 会触发 TypeError
                        EditResponse::class => __DIR__ . '/ClassMap/EditResponse.php',
                        // 兼容部分上游/代理返回 null 的图片 token 明细，避免类型异常
                        ImageResponseUsageInputTokensDetails::class => __DIR__ . '/ClassMap/ImageResponseUsageInputTokensDetails.php',
                        // 兼容部分上游/代理 4xx/5xx 返回 {"error": "字符串"}，原生 ErrorException 仅接受 array 会触发 TypeError
                        ErrorException::class => __DIR__ . '/ClassMap/ErrorException.php',
                    ],
                ],
            ],

            'dependencies' => [
                Client::class => ClientFactory::class,
                ClientContract::class => fn ($container) => $container->get(Client::class), // alias for Client::class
            ],
        ];
    }
}
