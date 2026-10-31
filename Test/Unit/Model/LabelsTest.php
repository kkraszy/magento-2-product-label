<?php
declare(strict_types=1);

namespace Kkkonrad\ProductLabels\Test\Unit\Model;

use Kkkonrad\ProductLabels\Model\Labels;
use PHPUnit\Framework\TestCase;

class LabelsTest extends TestCase
{
    private const SALE = ['{SAVE_PERCENT}' => '20', '{SAVE_AMOUNT}' => '$10.00', '{PRICE}' => '$40.00', '{SKU}' => 'MJ01'];
    private const FULL_PRICE = ['{SAVE_PERCENT}' => null, '{SAVE_AMOUNT}' => null, '{PRICE}' => '$50.00', '{SKU}' => 'MJ01'];

    /**
     * @dataProvider cases
     */
    public function testText(string $template, array $vars, ?string $expected): void
    {
        $this->assertSame($expected, Labels::text($template, $vars));
    }

    public static function cases(): array
    {
        return [
            'plain' => ['New', self::FULL_PRICE, 'New'],
            'percent' => ['-{SAVE_PERCENT}%', self::SALE, '-20%'],
            'two vars' => ['Save {SAVE_AMOUNT}, now {PRICE}', self::SALE, 'Save $10.00, now $40.00'],
            // "-%" on a full-price product would be nonsense: the label is dropped
            'discount var at full price' => ['-{SAVE_PERCENT}%', self::FULL_PRICE, null],
            'other var at full price' => ['{SKU} {PRICE}', self::FULL_PRICE, 'MJ01 $50.00'],
            'unknown braces left alone' => ['{FOO}', self::SALE, '{FOO}'],
        ];
    }
}
