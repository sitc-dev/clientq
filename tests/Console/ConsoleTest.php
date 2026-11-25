<?php

declare(strict_types=1);

namespace ClientQ\Tests\Console;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class ConsoleTest {
    public function __construct(
        public string $description,
        public string $expectedOutcome
    ) {
    }
}
