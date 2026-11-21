<?php

namespace Tests\Unit;

use App\Support\ArabicText;
use PHPUnit\Framework\TestCase;

class ArabicTextTest extends TestCase
{
    public function test_digits_typed_in_arabic_become_ascii(): void
    {
        $this->assertSame('0123456789', ArabicText::digitsToAscii('٠١٢٣٤٥٦٧٨٩'));
        $this->assertSame('0123456789', ArabicText::digitsToAscii('۰۱۲۳۴۵۶۷۸۹'));
    }

    public function test_the_same_name_typed_different_ways_has_one_search_key(): void
    {
        $this->assertSame(ArabicText::searchKey('احمد'), ArabicText::searchKey('أَحمـد'));
        $this->assertSame(ArabicText::searchKey('فاطمه'), ArabicText::searchKey('فاطمة'));
        $this->assertSame(ArabicText::searchKey('مصطفي'), ArabicText::searchKey('مصطفى'));
        $this->assertSame('محمد علي', ArabicText::searchKey("  محمد \t علي "));
    }
}
