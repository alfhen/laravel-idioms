<?php

namespace Tests\Hidden;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class InvoiceUploadsHiddenTest extends TestCase
{
    use RefreshDatabase;

    private const SENTINEL = 'zxqv-sentinel';

    /** @var list<string> */
    private array $disks = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Fake every configured disk, the media library's own and 'public' included,
        // so the test sees wherever a build chooses to store the file.
        $this->disks = array_values(array_unique(array_merge(
            array_keys((array) config('filesystems.disks')),
            array_filter([(string) config('media-library.disk_name'), 'public', 'local']),
        )));
        foreach ($this->disks as $disk) {
            Storage::fake($disk);
        }
    }

    private function pdf(string $marker, int $padTo = 0): string
    {
        $bytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% {$marker}\n";
        if ($padTo > strlen($bytes)) {
            $bytes .= '%'.str_repeat('0', $padTo - strlen($bytes) - 8)."\n";
        }

        return $bytes."trailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function realFile(string $bytes, string $name, ?string $mime = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hidden-inv');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    private function order(User $owner): Order
    {
        return Order::factory()->create(['user_id' => $owner->id]);
    }

    private function upload(User $user, Order $order, UploadedFile $file, array $extra = []): TestResponse
    {
        Sanctum::actingAs($user, ['*']);
        $response = $this->call(
            'POST',
            "/api/orders/{$order->getKey()}/invoice",
            $extra,
            [],
            ['invoice' => $file],
            $this->transformHeadersToServerVars(['Accept' => 'application/json']),
        );
        $this->app['auth']->forgetGuards();

        return $response;
    }

    private function download(User $user, Order $order): TestResponse
    {
        Sanctum::actingAs($user, ['*']);
        $response = $this->get("/api/orders/{$order->getKey()}/invoice", ['Accept' => 'application/json']);
        $this->app['auth']->forgetGuards();

        return $response;
    }

    private function body(TestResponse $response): string
    {
        $base = $response->baseResponse;
        if ($base instanceof BinaryFileResponse) {
            return (string) file_get_contents($base->getFile()->getPathname());
        }
        if ($base instanceof StreamedResponse) {
            return (string) $response->streamedContent();
        }

        return (string) $response->getContent();
    }

    /** @return list<string> "disk:path" of every stored file, generated derivatives left out */
    private function storedFiles(): array
    {
        $files = [];
        foreach ($this->disks as $disk) {
            foreach (Storage::disk($disk)->allFiles() as $path) {
                if (str_contains($path, 'conversions/') || str_contains($path, 'responsive-images/') || str_ends_with($path, '.gitignore')) {
                    continue;
                }
                $files[] = "{$disk}:{$path}";
            }
        }

        return $files;
    }

    private function mediaRowsFor(Order $order): int
    {
        if (! Schema::hasTable('media')) {
            return 0;
        }

        return \Illuminate\Support\Facades\DB::table('media')
            ->where('model_id', $order->getKey())
            ->whereIn('model_type', [Order::class, $order->getMorphClass()])
            ->count();
    }

    private function assertNoUnsignedStorageUrl(TestResponse $response): void
    {
        $content = str_replace('\\/', '/', (string) $response->getContent());
        preg_match_all('#\S*/storage/[^"\s]*#', $content, $matches);
        foreach ($matches[0] as $url) {
            $this->assertStringContainsString('signature=', $url, "response exposes a public storage URL: {$url}");
        }
    }

    private function assertUploaded(TestResponse $response): void
    {
        $this->assertContains($response->getStatusCode(), [200, 201], 'upload answered '.$response->getStatusCode().': '.$response->getContent());
    }

    public function test_an_invoice_is_never_stored_on_the_public_disk_or_exposed_by_a_public_url(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);

        $response = $this->upload($owner, $order, $this->realFile($this->pdf('first'), 'invoice.pdf'));

        $this->assertUploaded($response);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'invoice stored on the public disk');
        $this->assertCount(1, $this->storedFiles(), 'expected exactly one stored file');
        $this->assertNoUnsignedStorageUrl($response);
        $this->assertIsString($response->json('download_url'));
    }

    public function test_the_owner_downloads_exactly_the_bytes_uploaded(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);
        $bytes = $this->pdf('exact-bytes');
        $this->assertUploaded($this->upload($owner, $order, $this->realFile($bytes, 'invoice.pdf')));

        $download = $this->download($owner, $order);

        $download->assertOk();
        $this->assertSame($bytes, $this->body($download));
        $this->assertNoUnsignedStorageUrl($download);
    }

    public function test_another_user_cannot_download_the_invoice(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);
        $this->assertUploaded($this->upload($owner, $order, $this->realFile($this->pdf('private'), 'invoice.pdf')));

        $response = $this->download(User::factory()->create(), $order);

        $this->assertContains($response->getStatusCode(), [403, 404], 'non-owner download answered '.$response->getStatusCode());
        $this->assertStringNotContainsString('%PDF-', $this->body($response));
    }

    public function test_another_user_cannot_replace_the_invoice(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);
        $bytes = $this->pdf('original');
        $this->assertUploaded($this->upload($owner, $order, $this->realFile($bytes, 'invoice.pdf')));

        $response = $this->upload(User::factory()->create(), $order, $this->realFile($this->pdf('intruder'), 'invoice.pdf'));

        $this->assertContains($response->getStatusCode(), [403, 404], 'non-owner upload answered '.$response->getStatusCode());
        $this->assertSame($bytes, $this->body($this->download($owner, $order)));
    }

    public function test_a_guest_cannot_download(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);
        $this->assertUploaded($this->upload($owner, $order, $this->realFile($this->pdf('guest'), 'invoice.pdf')));

        $status = $this->getJson("/api/orders/{$order->getKey()}/invoice")->getStatusCode();

        $this->assertContains($status, [401, 403, 404], "guest download answered {$status}");
    }

    public function test_an_order_without_an_invoice_answers_404(): void
    {
        $owner = User::factory()->create();

        $this->download($owner, $this->order($owner))->assertNotFound();
    }

    public function test_the_stored_file_name_never_contains_the_client_file_name(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);

        $this->assertUploaded($this->upload($owner, $order, $this->realFile($this->pdf('named'), self::SENTINEL.' Jane Doe.pdf')));

        $files = $this->storedFiles();
        $this->assertCount(1, $files);
        foreach ($files as $file) {
            $this->assertStringNotContainsStringIgnoringCase('sentinel', $file, "stored path uses the client name: {$file}");
            $this->assertStringNotContainsStringIgnoringCase('jane', $file, "stored path uses the client name: {$file}");
        }
        if (Schema::hasTable('media') && Schema::hasColumn('media', 'file_name')) {
            foreach (\Illuminate\Support\Facades\DB::table('media')->pluck('file_name') as $fileName) {
                $this->assertStringNotContainsStringIgnoringCase('sentinel', (string) $fileName);
            }
        }
    }

    public function test_a_text_file_named_pdf_is_rejected_with_422(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);

        $response = $this->upload($owner, $order, $this->realFile("<?php echo 'not a pdf';\nhello\n", 'invoice.pdf', 'application/pdf'));

        $response->assertStatus(422);
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, $this->mediaRowsFor($order));
    }

    public function test_a_file_over_5_mb_is_rejected_with_422(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);

        $response = $this->upload($owner, $order, $this->realFile($this->pdf('big', 6 * 1024 * 1024), 'big.pdf'));

        $response->assertStatus(422);
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, $this->mediaRowsFor($order));
    }

    public function test_a_re_upload_leaves_exactly_one_file_for_the_order(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);
        $this->assertUploaded($this->upload($owner, $order, $this->realFile($this->pdf('first'), 'a.pdf')));
        $second = $this->pdf('second');

        $response = $this->upload($owner, $order, $this->realFile($second, 'b.pdf'));

        $this->assertUploaded($response);
        $this->assertNoUnsignedStorageUrl($response);
        $this->assertCount(1, $this->storedFiles(), 'old invoice file left behind: '.implode(', ', $this->storedFiles()));
        $this->assertLessThanOrEqual(1, $this->mediaRowsFor($order));
        $this->assertSame($second, $this->body($this->download($owner, $order)));
    }

    public function test_an_empty_text_field_named_like_the_file_does_not_bypass_validation(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);

        $response = $this->upload($owner, $order, $this->realFile("plain text\n", 'invoice.pdf', 'application/pdf'), ['invoice' => '']);

        $this->assertSame(422, $response->getStatusCode(), 'a non-PDF sent with an empty same-named text field answered '.$response->getStatusCode());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_an_empty_text_field_named_like_the_file_alongside_a_valid_pdf(): void
    {
        $owner = User::factory()->create();
        $order = $this->order($owner);
        $bytes = $this->pdf('shadowed');

        $response = $this->upload($owner, $order, $this->realFile($bytes, 'invoice.pdf'), ['invoice' => '']);

        if ($response->getStatusCode() === 422) {
            $this->assertSame([], $this->storedFiles());

            return;
        }
        $this->assertUploaded($response);
        $this->assertCount(1, $this->storedFiles());
        $this->assertSame($bytes, $this->body($this->download($owner, $order)));
    }
}
