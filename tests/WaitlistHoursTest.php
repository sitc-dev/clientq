<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib/waitlist-hours.php';

final class WaitlistHoursTest extends TestCase {
    public static function boundaries(): array {
        return [
            'before opening' => ['2026-11-19T07:59:59-05:00', false, true, false],
            'at opening' => ['2026-11-19T08:00:00-05:00', true, false, false],
            'before closing' => ['2026-11-19T16:29:59-05:00', true, false, false],
            'at closing' => ['2026-11-19T16:30:00-05:00', false, false, true],
            'next day' => ['2026-11-20T08:00:00-05:00', false, false, true],
            'UTC converted to Eastern' => ['2026-11-19T13:00:00+00:00', true, false, false],
        ];
    }

    #[DataProvider('boundaries')]
    public function testDailyHoursUseEasternTimeAndExcludeTheClosingBoundary(string $now, bool $open, bool $upcoming, bool $finished): void {
        $hours = ['date' => '2026-11-19', 'is_closed' => false, 'opening_time' => '08:00', 'closing_time' => '16:30'];
        $state = waitlistHoursState($hours, new DateTimeImmutable($now));
        self::assertSame($open, $state['is_open']);
        self::assertSame($upcoming, $state['is_upcoming']);
        self::assertSame($finished, $state['is_finished']);
    }

    public function testClosedDayOverridesOtherwiseAvailableHours(): void {
        $state = waitlistHoursState(
            ['date' => '2026-11-19', 'is_closed' => true, 'opening_time' => '08:00', 'closing_time' => '16:30'],
            new DateTimeImmutable('2026-11-19T10:00:00-05:00')
        );
        self::assertFalse($state['is_open']);
        self::assertFalse($state['is_upcoming']);
        self::assertTrue($state['is_finished']);
    }

    public function testCustomStaffStartTimeIsUpcomingBeforeTheShift(): void {
        $hours = ['date' => '2026-11-19', 'is_closed' => false, 'opening_time' => '11:15', 'closing_time' => '18:00'];
        $before = waitlistHoursState($hours, new DateTimeImmutable('2026-11-19T10:00:00-05:00'));
        self::assertTrue($before['is_upcoming']);
        self::assertFalse($before['is_finished']);
        self::assertSame('11:15 AM', $before['opening_label']);
        $started = waitlistHoursState($hours, new DateTimeImmutable('2026-11-19T11:15:00-05:00'));
        self::assertTrue($started['is_open']);
        self::assertFalse($started['is_upcoming']);
    }
}
