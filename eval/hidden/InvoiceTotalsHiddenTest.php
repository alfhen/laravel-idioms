<?php

namespace Tests\Hidden;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InvoiceTotalsHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
    }

    private function invoice(TestResponse $response): array
    {
        $json = $response->json();

        return is_array($json['data'] ?? null) && ! isset($json['id']) ? $json['data'] : $json;
    }

    private function create(array $payload): array
    {
        $response = $this->postJson('/api/invoices', $payload);
        $response->assertCreated();

        return $this->invoice($response);
    }

    private function line(string $price, int $quantity = 1, string $sku = 'SKU-1'): array
    {
        return ['sku' => $sku, 'unit_price' => $price, 'quantity' => $quantity];
    }

    private function assertRejected(string $currency, string $price): void
    {
        $response = $this->postJson('/api/invoices', [
            'currency' => $currency,
            'vat_rate' => 25,
            'lines' => [$this->line($price)],
        ]);

        $this->assertSame(422, $response->status(), "{$currency} \"{$price}\" should be rejected");
        $this->assertSame(0, DB::table('invoices')->count());
    }

    /** @return array<string, mixed> every value under a key ending in _minor, by dotted path */
    private function moneyValues(array $data, string $prefix = ''): array
    {
        $found = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_string($key) && str_ends_with($key, '_minor')) {
                if (is_array($value)) {
                    foreach ($value as $i => $item) {
                        $found["{$path}.{$i}"] = $item;
                    }
                } else {
                    $found[$path] = $value;
                }
            } elseif (is_array($value)) {
                $found += $this->moneyValues($value, $path);
            }
        }

        return $found;
    }

    private function moneyProjection(array $invoice): array
    {
        $keys = ['currency', 'subtotal_minor', 'discount_minor', 'net_minor', 'vat_minor', 'gross_minor'];
        $lineKeys = ['sku', 'quantity', 'unit_price_minor', 'subtotal_minor', 'discount_minor', 'net_minor', 'vat_minor', 'gross_minor'];

        $pick = function (array $row, array $keys): array {
            $picked = array_intersect_key($row, array_flip($keys));
            ksort($picked);

            return $picked;
        };

        return [
            ...$pick($invoice, $keys),
            'lines' => array_map(fn (array $line) => $pick($line, $lineKeys), $invoice['lines']),
        ];
    }

    public function test_an_eur_invoice_is_created_with_minor_unit_totals(): void
    {
        $invoice = $this->create([
            'currency' => 'EUR',
            'vat_rate' => 25,
            'lines' => [$this->line('10.00', 2)],
        ]);

        $this->assertSame('EUR', $invoice['currency']);
        $this->assertSame(2000, $invoice['subtotal_minor']);
        $this->assertSame(0, $invoice['discount_minor']);
        $this->assertSame(2000, $invoice['net_minor']);
        $this->assertSame(500, $invoice['vat_minor']);
        $this->assertSame(2500, $invoice['gross_minor']);
        $this->assertSame(1000, $invoice['lines'][0]['unit_price_minor']);
        $this->assertSame(1, DB::table('invoices')->count());
    }

    public function test_vat_is_rounded_half_up_per_line_and_totals_are_the_sum_of_lines(): void
    {
        $invoice = $this->create([
            'currency' => 'EUR',
            'vat_rate' => 25,
            'lines' => [$this->line('0.10', 1, 'A'), $this->line('0.10', 1, 'B'), $this->line('0.10', 1, 'C')],
        ]);

        foreach ($invoice['lines'] as $line) {
            $this->assertSame(10, $line['net_minor']);
            $this->assertSame(3, $line['vat_minor']);
            $this->assertSame(13, $line['gross_minor']);
        }
        $this->assertSame(30, $invoice['net_minor']);
        $this->assertSame(9, $invoice['vat_minor']);
        $this->assertSame(39, $invoice['gross_minor']);
    }

    public function test_a_decimal_price_converts_to_exact_minor_units(): void
    {
        $invoice = $this->create([
            'currency' => 'EUR',
            'vat_rate' => 0,
            'lines' => [$this->line('19.99', 3)],
        ]);

        $this->assertSame(1999, $invoice['lines'][0]['unit_price_minor']);
        $this->assertSame(5997, $invoice['lines'][0]['subtotal_minor']);
        $this->assertSame(5997, $invoice['net_minor']);
        $this->assertSame(5997, $invoice['gross_minor']);
    }

    public function test_jpy_prices_have_no_minor_digits(): void
    {
        $invoice = $this->create([
            'currency' => 'JPY',
            'vat_rate' => 10,
            'lines' => [$this->line('150', 2)],
        ]);

        $this->assertSame(150, $invoice['lines'][0]['unit_price_minor']);
        $this->assertSame(300, $invoice['net_minor']);
        $this->assertSame(30, $invoice['vat_minor']);
        $this->assertSame(330, $invoice['gross_minor']);
    }

    public function test_dkk_prices_have_two_minor_digits(): void
    {
        $invoice = $this->create([
            'currency' => 'DKK',
            'vat_rate' => 25,
            'lines' => [$this->line('12.5')],
        ]);

        $this->assertSame(1250, $invoice['lines'][0]['unit_price_minor']);
        $this->assertSame(1563, $invoice['gross_minor']);
    }

    public function test_a_jpy_price_with_decimals_is_rejected(): void
    {
        $this->assertRejected('JPY', '150.5');
    }

    public function test_an_eur_price_with_three_decimals_is_rejected(): void
    {
        $this->assertRejected('EUR', '12.345');
    }

    public function test_an_exponent_notation_price_is_rejected(): void
    {
        $this->assertRejected('EUR', '1e2');
    }

    public function test_comma_negative_and_text_prices_are_rejected(): void
    {
        $this->assertRejected('EUR', '12,30');
        $this->assertRejected('EUR', '-1');
        $this->assertRejected('EUR', 'abc');
    }

    public function test_split_parts_add_up_to_the_gross_and_differ_by_at_most_one_minor_unit(): void
    {
        $invoice = $this->create([
            'currency' => 'EUR',
            'vat_rate' => 25,
            'lines' => [$this->line('80.00')],
        ]);
        $this->assertSame(10000, $invoice['gross_minor']);

        foreach ([3, 7] as $count) {
            $response = $this->postJson("/api/invoices/{$invoice['id']}/split", ['parts' => $count]);
            $response->assertSuccessful();
            $parts = $response->json('parts_minor');

            $this->assertCount($count, $parts);
            foreach ($parts as $part) {
                $this->assertIsInt($part);
            }
            $this->assertSame(10000, array_sum($parts), "split into {$count}");
            $this->assertLessThanOrEqual(1, max($parts) - min($parts), "split into {$count}");
        }
    }

    public function test_the_discount_is_spread_so_lines_add_up_to_the_invoice(): void
    {
        $cases = [
            ['discount' => '1.00', 'prices' => ['3.33', '3.33', '3.34'], 'discount_minor' => 100, 'subtotal_minor' => 1000],
            ['discount' => '0.10', 'prices' => ['1.00', '1.00', '1.00'], 'discount_minor' => 10, 'subtotal_minor' => 300],
        ];

        foreach ($cases as $case) {
            $invoice = $this->create([
                'currency' => 'EUR',
                'vat_rate' => 25,
                'discount' => $case['discount'],
                'lines' => array_map(fn (string $price) => $this->line($price), $case['prices']),
            ]);
            $lines = collect($invoice['lines']);

            $this->assertSame($case['subtotal_minor'], $invoice['subtotal_minor']);
            $this->assertSame($case['discount_minor'], $invoice['discount_minor']);
            $this->assertSame($case['discount_minor'], $lines->sum('discount_minor'), "discount {$case['discount']}");
            $this->assertSame($case['subtotal_minor'] - $case['discount_minor'], $invoice['net_minor']);
            $this->assertSame($invoice['net_minor'], $lines->sum('net_minor'));
            foreach ($lines as $line) {
                $this->assertGreaterThanOrEqual(0, $line['discount_minor']);
                $this->assertSame($line['subtotal_minor'] - $line['discount_minor'], $line['net_minor']);
                $this->assertSame(intdiv($line['net_minor'] * 25 + 50, 100), $line['vat_minor']);
            }
            $this->assertSame($lines->sum('vat_minor'), $invoice['vat_minor']);
            $this->assertSame($lines->sum('gross_minor'), $invoice['gross_minor']);
        }
    }

    public function test_every_money_value_in_responses_is_an_integer(): void
    {
        $created = $this->create([
            'currency' => 'EUR',
            'vat_rate' => 25,
            'discount' => '0.50',
            'lines' => [$this->line('19.99', 3, 'A'), $this->line('0.10', 1, 'B'), $this->line('3.33', 2, 'C')],
        ]);
        $fetched = $this->invoice($this->getJson("/api/invoices/{$created['id']}")->assertOk());
        $split = $this->postJson("/api/invoices/{$created['id']}/split", ['parts' => 3])->assertSuccessful()->json();

        foreach (['create' => $created, 'show' => $fetched, 'split' => $split] as $source => $data) {
            $values = $this->moneyValues($data);
            $this->assertNotEmpty($values, $source);
            foreach ($values as $path => $value) {
                $this->assertIsInt($value, "{$source}: {$path}");
            }
        }
    }

    public function test_get_returns_the_same_money_as_the_create_response(): void
    {
        $created = $this->create([
            'currency' => 'EUR',
            'vat_rate' => 25,
            'discount' => '1.00',
            'lines' => [$this->line('19.99', 3, 'A'), $this->line('0.10', 1, 'B'), $this->line('3.33', 2, 'C')],
        ]);

        $fetched = $this->invoice($this->getJson("/api/invoices/{$created['id']}")->assertOk());

        $this->assertSame($this->moneyProjection($created), $this->moneyProjection($fetched));
    }
}
