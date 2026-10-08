<?php

declare(strict_types=1);

namespace OpenAI\Responses\Images;

/**
 * 覆盖 SDK 原生 DTO：部分上游/代理（如 grsai）会返回 null 的 token 明细，
 * 原版把 text_tokens / image_tokens 直接塞进 readonly int 会触发类型异常，
 * 这里宽松处理为可空并兜底 0。
 */

final class ImageResponseUsageInputTokensDetails
{
    private function __construct(
        public readonly int $textTokens,
        public readonly int $imageTokens,
    ) {}

    /**
     * @param  array{text_tokens?: int|null, image_tokens?: int|null}  $attributes
     */
    public static function from(array $attributes): self
    {
        return new self(
            $attributes['text_tokens'] ?? 0,
            $attributes['image_tokens'] ?? 0,
        );
    }

    /**
     * @return array{text_tokens: int, image_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'text_tokens' => $this->textTokens,
            'image_tokens' => $this->imageTokens,
        ];
    }
}
