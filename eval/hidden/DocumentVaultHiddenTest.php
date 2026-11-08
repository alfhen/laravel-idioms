<?php

namespace Tests\Hidden;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentVaultHiddenTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function tearDown(): void
    {
        foreach (Document::query()->pluck('path') as $path) {
            Storage::disk('local')->delete($path);
            Storage::disk('public')->delete($path);
        }
        parent::tearDown();
    }

    private function realFile(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hidden-doc');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function upload(User $user, UploadedFile $file, string $title = 'Tax return')
    {
        Sanctum::actingAs($user, ['*']);
        $response = $this->post('/api/documents', ['file' => $file, 'title' => $title], ['Accept' => 'application/json']);
        $this->app['auth']->forgetGuards();

        return $response;
    }

    private function linkFor(User $user, int $id)
    {
        Sanctum::actingAs($user, ['*']);
        $response = $this->getJson("/api/documents/{$id}/link");
        $this->app['auth']->forgetGuards();

        return $response;
    }

    private function storedDocument(User $owner, string $name = 'my report.png'): Document
    {
        $id = $this->upload($owner, $this->realFile(base64_decode(self::PNG), $name))->assertCreated()->json('id');

        return Document::query()->findOrFail($id);
    }

    public function test_an_upload_is_stored_privately_under_a_generated_name(): void
    {
        $doc = $this->storedDocument(User::factory()->create(), 'my report.png');

        $this->assertSame('my report.png', $doc->original_name);
        $this->assertStringNotContainsString('my report', $doc->path);
        $this->assertStringNotContainsString('..', $doc->path);
        $this->assertTrue(Storage::disk('local')->exists($doc->path), 'file not on the private local disk');
        $this->assertFalse(Storage::disk('public')->exists($doc->path), 'file on the public disk');
    }

    public function test_a_file_that_only_claims_to_be_an_image_is_rejected(): void
    {
        $response = $this->upload(User::factory()->create(), $this->realFile('<?php echo "pwned";', 'scan.png'));

        $response->assertStatus(422);
        $this->assertSame(0, Document::count());
    }

    public function test_a_file_over_5_mb_is_rejected(): void
    {
        $this->upload(User::factory()->create(), UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'))
            ->assertStatus(422);
    }

    public function test_the_link_downloads_the_file_without_logging_in(): void
    {
        $owner = User::factory()->create();
        $doc = $this->storedDocument($owner);

        $url = $this->linkFor($owner, $doc->id)->assertOk()->json('url');
        $download = $this->get($url);

        $download->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame(base64_decode(self::PNG), $download->streamedContent() ?: $download->getContent());
    }

    public function test_the_link_stops_working_after_10_minutes(): void
    {
        $owner = User::factory()->create();
        $doc = $this->storedDocument($owner);
        $url = $this->linkFor($owner, $doc->id)->json('url');

        $this->travel(11)->minutes();

        $status = $this->get($url)->status();
        $this->assertTrue($status >= 400 && $status < 500, "expired link answered {$status}");
    }

    public function test_a_tampered_link_is_refused(): void
    {
        $owner = User::factory()->create();
        $doc = $this->storedDocument($owner);
        $url = $this->linkFor($owner, $doc->id)->json('url');
        $tampered = preg_replace('/signature=([0-9a-f])/', 'signature=x', $url);
        $this->assertNotSame($url, $tampered, 'link carries no signature');

        $status = $this->get($tampered)->status();
        $this->assertTrue($status >= 400 && $status < 500, "tampered link answered {$status}");
    }

    public function test_another_user_cannot_get_a_link(): void
    {
        $doc = $this->storedDocument(User::factory()->create());

        $this->linkFor(User::factory()->create(), $doc->id)->assertNotFound();
    }

    public function test_a_link_to_a_missing_file_answers_404(): void
    {
        $owner = User::factory()->create();
        $doc = $this->storedDocument($owner);
        $url = $this->linkFor($owner, $doc->id)->json('url');
        Storage::disk('local')->delete($doc->path);

        $this->get($url)->assertNotFound();
    }

    public function test_guest_without_accept_header_gets_json_401(): void
    {
        $response = $this->post('/api/documents', ['title' => 'x', 'file' => $this->realFile(base64_decode(self::PNG), 'a.png')]);

        $response->assertUnauthorized();
        $this->assertIsString($response->json('message'));
    }
}
