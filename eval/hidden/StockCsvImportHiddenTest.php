<?php

namespace Tests\Hidden;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockCsvImportHiddenTest extends TestCase
{
    use RefreshDatabase;

    /** A real file on disk, so content-based MIME detection runs as it does in production. */
    private function upload(string $content, string $name = 'stock.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hidden-csv');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function import(UploadedFile $file)
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        return $this->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json']);
    }

    private function quantities(): array
    {
        return DB::table('stock_levels')->orderBy('sku')->pluck('quantity', 'sku')->map(fn ($q) => (int) $q)->all();
    }

    private function errorLines($response): array
    {
        return collect($response->json('errors'))->pluck('line')->map(fn ($l) => (int) $l)->sort()->values()->all();
    }

    public function test_a_bom_crlf_file_with_padded_mixed_case_headers_imports_every_row(): void
    {
        $response = $this->import($this->upload("\xEF\xBB\xBF SKU , Quantity \r\nA1,5\r\nB2,7\r\nC3,0\r\n"));

        $response->assertOk();
        $this->assertSame(3, $response->json('imported'));
        $this->assertSame([], $response->json('errors'));
        $this->assertSame(['A1' => 5, 'B2' => 7, 'C3' => 0], $this->quantities());
    }

    public function test_columns_are_found_by_name_in_any_order(): void
    {
        $response = $this->import($this->upload("quantity,sku\n4,A1\n9,B2\n"));

        $response->assertOk();
        $this->assertSame(['A1' => 4, 'B2' => 9], $this->quantities());
    }

    public function test_invalid_rows_are_reported_with_their_file_line_and_valid_rows_still_import(): void
    {
        $csv = "sku,quantity\nA1,5\nB2,7,extra\nC3,-3\nD4,abc\n,6\nE5,2\n";

        $response = $this->import($this->upload($csv));

        $response->assertOk();
        $this->assertSame(2, $response->json('imported'));
        $this->assertSame([3, 4, 5, 6], $this->errorLines($response));
        $this->assertSame(['A1' => 5, 'E5' => 2], $this->quantities());
    }

    public function test_blank_lines_are_ignored_but_still_counted_in_line_numbers(): void
    {
        $response = $this->import($this->upload("sku,quantity\nA1,5\n\nB2,x\n\n"));

        $response->assertOk();
        $this->assertSame(1, $response->json('imported'));
        $this->assertSame([4], $this->errorLines($response));
    }

    public function test_importing_a_sku_again_updates_it(): void
    {
        $this->import($this->upload("sku,quantity\nA1,5\n"))->assertOk();
        $this->app['auth']->forgetGuards();

        $this->import($this->upload("sku,quantity\nA1,8\n"))->assertOk();

        $this->assertSame(['A1' => 8], $this->quantities());
    }

    public function test_a_header_only_csv_is_accepted(): void
    {
        $response = $this->import($this->upload("sku,quantity\n"));

        $response->assertOk();
        $this->assertSame(0, $response->json('imported'));
    }

    public function test_a_file_without_both_headers_is_rejected(): void
    {
        $this->import($this->upload("sku,qty\nA1,5\n"))->assertStatus(422);
        $this->assertSame(0, DB::table('stock_levels')->count());
    }

    public function test_a_non_csv_file_is_rejected(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        $this->import($this->upload($png, 'stock.png'))->assertStatus(422);
        $this->assertSame(0, DB::table('stock_levels')->count());
    }

    public function test_a_file_over_5_mb_is_rejected(): void
    {
        $this->import(UploadedFile::fake()->create('stock.csv', 6000, 'text/csv'))->assertStatus(422);
    }

    public function test_guest_without_accept_header_gets_json_401(): void
    {
        $response = $this->post('/api/stock-imports', ['file' => $this->upload("sku,quantity\nA1,5\n")]);

        $response->assertUnauthorized();
        $this->assertIsString($response->json('message'));
    }
}
