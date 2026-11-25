<?php

declare(strict_types=1);

namespace ClientQ\Tests\Console;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use ReflectionClass;
use ReflectionMethod;

final class ConsoleTestReporterExtension implements Extension {
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters
    ): void {
        $reporter = new ConsoleTestReporter();
        $facade->registerSubscribers(
            new TestStartedConsoleSubscriber($reporter),
            new TestPassedConsoleSubscriber($reporter),
            new TestFailedConsoleSubscriber($reporter),
            new TestErroredConsoleSubscriber($reporter),
            new TestSkippedConsoleSubscriber($reporter),
            new TestIncompleteConsoleSubscriber($reporter)
        );
    }
}

final class ConsoleTestReporter {
    public function started(Test $test): void {
        $details = $this->details($test);
        $this->write(sprintf(
            "\nTEST: %s\nDESCRIPTION: %s\nEXPECTED: %s\n",
            $details['name'],
            $details['description'],
            $details['expected']
        ));
    }

    public function finished(string $status, ?string $message = null): void {
        $output = 'RESULT: ' . $status;
        if ($message !== null && trim($message) !== '') {
            $output .= ' - ' . trim($message);
        }
        $this->write($output . "\n");
    }

    private function details(Test $test): array {
        if (!$test instanceof TestMethod) {
            return [
                'name' => $test->id(),
                'description' => 'Run the configured PHPUnit test.',
                'expected' => 'The test completes without failures or errors.',
            ];
        }

        $metadata = $this->metadata($test);
        return [
            'name' => $test->testDox()->prettifiedMethodName(),
            'description' => $metadata?->description
                ?? $test->testDox()->prettifiedMethodName(),
            'expected' => $metadata?->expectedOutcome
                ?? 'All assertions pass without errors.',
        ];
    }

    private function metadata(TestMethod $test): ?ConsoleTest {
        $method = new ReflectionMethod($test->className(), $test->methodName());
        $attributes = $method->getAttributes(ConsoleTest::class);
        if ($attributes) {
            return $attributes[0]->newInstance();
        }

        $class = new ReflectionClass($test->className());
        $attributes = $class->getAttributes(ConsoleTest::class);
        return $attributes ? $attributes[0]->newInstance() : null;
    }

    private function write(string $message): void {
        fwrite(STDOUT, $message);
    }
}

final readonly class TestStartedConsoleSubscriber implements PreparationStartedSubscriber {
    public function __construct(private ConsoleTestReporter $reporter) {
    }

    public function notify(PreparationStarted $event): void {
        $this->reporter->started($event->test());
    }
}

final readonly class TestPassedConsoleSubscriber implements PassedSubscriber {
    public function __construct(private ConsoleTestReporter $reporter) {
    }

    public function notify(Passed $event): void {
        $this->reporter->finished('PASS');
    }
}

final readonly class TestFailedConsoleSubscriber implements FailedSubscriber {
    public function __construct(private ConsoleTestReporter $reporter) {
    }

    public function notify(Failed $event): void {
        $this->reporter->finished('FAIL', $event->throwable()->message());
    }
}

final readonly class TestErroredConsoleSubscriber implements ErroredSubscriber {
    public function __construct(private ConsoleTestReporter $reporter) {
    }

    public function notify(Errored $event): void {
        $this->reporter->finished('ERROR', $event->throwable()->message());
    }
}

final readonly class TestSkippedConsoleSubscriber implements SkippedSubscriber {
    public function __construct(private ConsoleTestReporter $reporter) {
    }

    public function notify(Skipped $event): void {
        $this->reporter->finished('SKIP', $event->message());
    }
}

final readonly class TestIncompleteConsoleSubscriber implements MarkedIncompleteSubscriber {
    public function __construct(private ConsoleTestReporter $reporter) {
    }

    public function notify(MarkedIncomplete $event): void {
        $this->reporter->finished('INCOMPLETE', $event->throwable()->message());
    }
}
