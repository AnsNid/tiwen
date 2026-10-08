<?php

declare(strict_types=1);

namespace App\openai\Facade;

use xphp\Context\ApplicationContext;
use OpenAI\Contracts\ClientContract;

/**
 * @method static \OpenAI\Resources\Assistants assistants()
 * @method static \OpenAI\Resources\Audio audio()
 * @method static \OpenAI\Resources\Batches batches()
 * @method static \OpenAI\Resources\Chat chat()
 * @method static \OpenAI\Resources\Completions completions()
 * @method static \OpenAI\Resources\Embeddings embeddings()
 * @method static \OpenAI\Resources\Edits edits()
 * @method static \OpenAI\Resources\Files files()
 * @method static \OpenAI\Resources\FineTunes fineTunes()
 * @method static \OpenAI\Resources\Images images()
 * @method static \OpenAI\Resources\Models models()
 * @method static \OpenAI\Resources\Moderations moderations()
 * @method static \OpenAI\Resources\Threads threads()
 * @method static \OpenAI\Resources\VectorStores vectorStores()
 */
class OpenAI {

    public static function __callStatic(string $name, array $arguments) {
        $instance = ApplicationContext::getContainer()->get(ClientContract::class);
        return $instance->{$name}(...$arguments);
    }
}
