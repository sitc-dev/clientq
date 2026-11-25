<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/reservations.php';

final class ReservationAvailabilityTest extends TestCase {
    private function schedule(): array {
        return ['days' => [2, 3, 4, 5, 6, 7], 'opening_time' => '08:30', 'closing_time' => '17:00', 'days_ahead' => 14];
    }

    public function testDefaultSlotsAndPartyDuration(): void {
        $now = new DateTimeImmutable('2026-11-14 07:00', new DateTimeZone('America/New_York'));
        $single = reservationSlotStarts($this->schedule(), '2026-11-14', 1, [], $now);
        self::assertCount(12, $single);
        self::assertSame('08:30', $single[0]);
        self::assertSame('16:45', end($single));
        $party = reservationSlotStarts($this->schedule(), '2026-11-14', 3, [], $now);
        self::assertCount(10, $party);
        self::assertSame('15:15', end($party));
    }

    public function testReservedSlotsBlockEveryOverlappingPartyStart(): void {
        $now = new DateTimeImmutable('2026-11-14 07:00', new DateTimeZone('America/New_York'));
        $slots = reservationSlotStarts($this->schedule(), '2026-11-14', 2, [['starts_at' => '2026-11-14 09:15:00', 'ends_at' => '2026-11-14 10:00:00']], $now);
        self::assertNotContains('08:30', $slots);
        self::assertNotContains('09:15', $slots);
        self::assertContains('10:00', $slots);
        self::assertNotContains('16:45', $slots);
    }

    public function testClosedDayAndFullyBookedDay(): void {
        $now = new DateTimeImmutable('2026-11-14 07:00', new DateTimeZone('America/New_York'));
        self::assertSame([], reservationSlotStarts($this->schedule(), '2026-11-16', 1, [], $now));
        $all = reservationSlotStarts($this->schedule(), '2026-11-14', 1, [], $now);
        $occupied = array_map(fn(string $time): array => [
            'starts_at' => '2026-11-14 ' . $time . ':00',
            'ends_at' => reservationDate('2026-11-14')->modify($time)->modify('+45 minutes')->format('Y-m-d H:i:s'),
        ], $all);
        self::assertSame([], reservationSlotStarts($this->schedule(), '2026-11-14', 1, $occupied, $now));
    }

    public function testPastTimesAndEasternBookingWindow(): void {
        $now = new DateTimeImmutable('2026-11-14 15:00', new DateTimeZone('UTC'));
        $slots = reservationSlotStarts($this->schedule(), '2026-11-14', 1, [], $now);
        self::assertSame('10:45', $slots[0]);
        self::assertNotEmpty(reservationSlotStarts($this->schedule(), '2026-11-28', 1, [], $now));
        $this->expectException(InvalidArgumentException::class);
        reservationSlotStarts($this->schedule(), '2026-11-29', 1, [], $now);
    }

    public function testInvalidDateCannotNormalizeIntoAnotherMonth(): void {
        $this->expectException(InvalidArgumentException::class);
        reservationDate('2026-02-30');
    }

    public function testChangingOpeningTimeDoesNotAllowOverlappingExistingBookings(): void {
        $schedule = $this->schedule();
        $schedule['opening_time'] = '09:00';
        $occupied = [['starts_at' => '2026-11-14 08:30:00', 'ends_at' => '2026-11-14 10:00:00']];
        $now = new DateTimeImmutable('2026-11-14 07:00', new DateTimeZone('America/New_York'));
        $slots = reservationSlotStarts($schedule, '2026-11-14', 1, $occupied, $now);
        self::assertNotContains('09:00', $slots);
        self::assertNotContains('09:45', $slots);
        self::assertContains('10:30', $slots);
    }

    public function testScheduleValidationAllowsClosingAllDaysAndRejectsInvalidHours(): void {
        self::assertSame([], validateReservationSchedule(['days' => [], 'opening_time' => '08:30', 'closing_time' => '17:00', 'days_ahead' => 14])['days']);
        $this->expectException(InvalidArgumentException::class);
        validateReservationSchedule(['days' => [2], 'opening_time' => '17:00', 'closing_time' => '08:30', 'days_ahead' => 14]);
    }
}
