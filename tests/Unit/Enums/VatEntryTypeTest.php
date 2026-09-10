<?php

namespace Tests\Unit\Enums;

use App\Domains\Accounting\Enums\VatEntryType;
use PHPUnit\Framework\TestCase;

class VatEntryTypeTest extends TestCase
{
    public function test_input_has_correct_value(): void
    {
        $this->assertSame('input', VatEntryType::Input->value);
    }

    public function test_output_has_correct_value(): void
    {
        $this->assertSame('output', VatEntryType::Output->value);
    }

    public function test_input_investment_has_correct_value(): void
    {
        $this->assertSame('input_investment', VatEntryType::InputInvestment->value);
    }

    public function test_from_valid_values(): void
    {
        $this->assertSame(VatEntryType::Input, VatEntryType::from('input'));
        $this->assertSame(VatEntryType::InputInvestment, VatEntryType::from('input_investment'));
        $this->assertSame(VatEntryType::Output, VatEntryType::from('output'));
    }
}
