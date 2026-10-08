<?php

declare(strict_types=1);

namespace OpenAI\Responses\Chat;

use OpenAI\Contracts\ResponseContract;
use OpenAI\Responses\Concerns\ArrayAccessible;
use OpenAI\Testing\Responses\Concerns\Fakeable;

/**
 * 为兼容 MiMo 而添加的类。
 *
 * MiMo 的 TTS 响应中 audio.expires_at 可能返回 null（甚至缺失），
 * 原版 SDK 将其强类型为 int，会抛 TypeError，故此处放宽为可空。
 */
final class CreateResponseChoiceAudio implements ResponseContract
{
    /**
     * @use ArrayAccessible<CreateResponseChoiceAudioType>
     */
    use ArrayAccessible;

    use Fakeable;

    private function __construct(
        public readonly ?string $id,
        public readonly ?string $data,
        public readonly ?int $expiresAt,
        public readonly ?string $transcript,
    ) {}

    /**
     * @param  array{id?: string, data?: string, expires_at?: int|null, transcript?: string}  $attributes
     */
    public static function from(array $attributes): self
    {
        return new self(
            id: $attributes['id'] ?? null,
            data: $attributes['data'] ?? null,
            expiresAt: $attributes['expires_at'] ?? null,
            transcript: $attributes['transcript'] ?? null,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'data' => $this->data,
            'expires_at' => $this->expiresAt,
            'transcript' => $this->transcript,
        ];
    }
}
