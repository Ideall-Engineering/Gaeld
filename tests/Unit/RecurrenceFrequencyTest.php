<?php

namespace Tests\Unit;

use App\Domains\Invoicing\Enums\RecurrenceFrequency;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RecurrenceFrequencyTest extends TestCase
{
    #[DataProvider('nextDateProvider')]
    public function test_next_date(RecurrenceFrequency $frequency, string $from, string $expected): void
    {
        $result = $frequency->nextDate(Carbon::parse($from));

        $this->assertSame($expected, $result->toDateString());
    }

    public function test_the_anchor_day_returns_after_a_short_month(): void
    {
        $this->assertSame('2026-03-31', RecurrenceFrequency::Monthly->nextDate(Carbon::parse('2026-02-28'), 31)->toDateString());
        $this->assertSame('2026-04-30', RecurrenceFrequency::Monthly->nextDate(Carbon::parse('2026-03-31'), 31)->toDateString());
    }

    public static function nextDateProvider(): array
    {
        return [
            'weekly' => [RecurrenceFrequency::Weekly, '2026-01-01', '2026-01-08'],
            'monthly' => [RecurrenceFrequency::Monthly, '2026-01-15', '2026-02-15'],
            // A short month is not skipped: the 31st falls on its last day.
            'monthly end of month' => [RecurrenceFrequency::Monthly, '2026-01-31', '2026-02-28'],
            'quarterly end of month' => [RecurrenceFrequency::Quarterly, '2026-08-31', '2026-11-30'],
            'yearly leap day' => [RecurrenceFrequency::Yearly, '2028-02-29', '2029-02-28'],
            'quarterly' => [RecurrenceFrequency::Quarterly, '2026-01-01', '2026-04-01'],
            'yearly' => [RecurrenceFrequency::Yearly, '2026-03-15', '2027-03-15'],
        ];
    }

    public function test_all_cases_exist(): void
    {
        $this->assertCount(4, RecurrenceFrequency::cases());
    }

    public function test_values(): void
    {
        $this->assertSame('weekly', RecurrenceFrequency::Weekly->value);
        $this->assertSame('monthly', RecurrenceFrequency::Monthly->value);
        $this->assertSame('quarterly', RecurrenceFrequency::Quarterly->value);
        $this->assertSame('yearly', RecurrenceFrequency::Yearly->value);
    }
}
