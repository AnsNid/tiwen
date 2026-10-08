<?php

declare(strict_types=1);

namespace App\mail\Factory;

use App\mail\Markdown;
use xphp\Contract\ConfigInterface;
use xphp\ViewEngine\Contract\FactoryInterface;

class MarkdownFactory {
    public function __construct(protected readonly ConfigInterface $config, protected readonly FactoryInterface $factory) {
    }

    public function __invoke() {
        return new Markdown($this->factory, $this->config->get('mail.markdown', []));
    }
}
