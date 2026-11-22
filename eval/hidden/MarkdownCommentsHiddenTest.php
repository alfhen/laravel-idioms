<?php

namespace Tests\Hidden;

use App\Models\Article;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarkdownCommentsHiddenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00', 'UTC'));

        $this->user = User::factory()->create(['name' => 'Commenting Person']);
        $this->article = Article::factory()->create(['title' => 'Release notes']);
    }

    private function postComment(string $body): void
    {
        $response = $this->actingAs($this->user)->post("/articles/{$this->article->getRouteKey()}/comments", ['body' => $body]);

        $this->assertLessThan(400, $response->status(), "posting a comment answered {$response->status()}");
    }

    /** @return list<array<string, mixed>> */
    private function jsonPage(int $page): array
    {
        $response = $this->getJson("/articles/{$this->article->getRouteKey()}/comments?page={$page}");
        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data, 'the comments JSON has no data array');

        return array_values($data);
    }

    private function jsonHtmlFor(string $marker): string
    {
        foreach ($this->jsonPage(1) as $item) {
            if (is_string($item['html'] ?? null) && str_contains($item['html'], $marker)) {
                return $item['html'];
            }
        }
        $this->fail("no comment in the JSON has an html field containing {$marker}");
    }

    private function pageHtml(int $page = 1): string
    {
        $response = $this->get("/articles/{$this->article->getRouteKey()}?page={$page}");
        $response->assertOk();

        return $response->getContent();
    }

    private function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    /**
     * Fails when the markup holds a live script, event handler, embedded frame or javascript: URL.
     * With a sentinel, only elements carrying it count, so the page's own layout scripts are ignored.
     */
    private function assertInert(string $html, string $where, ?string $sentinel = null): void
    {
        $carries = fn (string $text): bool => $sentinel === null || str_contains($text, $sentinel);

        foreach ($this->dom($html)->query('//*') as $element) {
            /** @var DOMElement $element */
            $tag = strtolower($element->nodeName);
            $markup = $element->ownerDocument->saveHTML($element);

            if ($tag === 'script') {
                $this->assertFalse($carries($element->textContent.$markup), "{$where} contains a live <script> element");
            }
            if (in_array($tag, ['iframe', 'object', 'embed'], true)) {
                $this->assertFalse($carries($markup), "{$where} contains a live <{$tag}> element");
            }
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = $attribute->nodeValue ?? '';
                if (str_starts_with($name, 'on')) {
                    $this->assertFalse($carries($value), "{$where} has an element with an {$name} handler");
                }
                if (in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href'], true)) {
                    $normalised = strtolower(preg_replace('/[\x00-\x20]+/', '', $value));
                    $this->assertFalse(str_starts_with($normalised, 'javascript:'), "{$where} has a javascript: {$name}");
                }
            }
        }
    }

    private function assertRendersBoldAndLink(string $html, string $where): void
    {
        $xpath = $this->dom($html);

        $bold = false;
        foreach ($xpath->query('//strong | //b') as $element) {
            $bold = $bold || trim($element->textContent) === 'bold words';
        }
        $this->assertTrue($bold, "{$where} does not render **bold words** as bold");

        $link = false;
        foreach ($xpath->query('//a') as $element) {
            /** @var DOMElement $element */
            $link = $link || ($element->getAttribute('href') === 'https://example.com/docs' && trim($element->textContent) === 'our docs');
        }
        $this->assertTrue($link, "{$where} does not render [our docs](https://example.com/docs) as a link");
    }

    /** @return list<string> markers found, in page order */
    private function markers(string $text): array
    {
        preg_match_all('/Tie(\d\d)q/', $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    public function test_a_script_tag_is_inert_in_the_json_and_on_the_page(): void
    {
        $this->postComment("Marker01a says hi\n\n<script>alert('XSS-A')</script>\n\nAnd inline <script>alert('XSS-A')</script> too");

        $this->assertInert($this->jsonHtmlFor('Marker01a'), 'the JSON html');
        $page = $this->pageHtml();
        $this->assertStringContainsString('Marker01a', $page);
        $this->assertInert($page, 'the article page', 'XSS-A');
    }

    public function test_an_image_with_an_event_handler_is_inert_in_the_json_and_on_the_page(): void
    {
        $this->postComment("Marker02b look <img src=x onerror=alert(1)> <img src=x onerror=alert(1729)>\n\n<img src=x onerror=\"alert(1729)\">");

        $this->assertInert($this->jsonHtmlFor('Marker02b'), 'the JSON html');
        $page = $this->pageHtml();
        $this->assertStringContainsString('Marker02b', $page);
        $this->assertInert($page, 'the article page', '1729');
    }

    public function test_raw_html_is_inert_in_the_json_and_on_the_page(): void
    {
        $this->postComment("Marker03c\n\n<iframe src=\"https://evil.example/XSS-C\"></iframe>\n\n<div onmouseover=\"alert('XSS-C')\">hover</div>\n\nSee <a href=\"javascript:alert('XSS-C')\">this</a> and <svg onload=\"alert('XSS-C')\"></svg>");

        $this->assertInert($this->jsonHtmlFor('Marker03c'), 'the JSON html');
        $page = $this->pageHtml();
        $this->assertStringContainsString('Marker03c', $page);
        $this->assertInert($page, 'the article page', 'XSS-C');
    }

    public function test_a_javascript_markdown_link_is_inert_in_the_json_and_on_the_page(): void
    {
        $this->postComment("Marker04d [x](javascript:alert(1)) and [y](JavaScript:alert('XSS-D'))");

        $this->assertInert($this->jsonHtmlFor('Marker04d'), 'the JSON html');
        $page = $this->pageHtml();
        $this->assertStringContainsString('Marker04d', $page);
        $this->assertInert($page, 'the article page', 'XSS-D');
    }

    public function test_bold_and_https_links_still_render_in_the_json_and_on_the_page(): void
    {
        $this->postComment('Marker05e has **bold words** and [our docs](https://example.com/docs).');

        $this->assertRendersBoldAndLink($this->jsonHtmlFor('Marker05e'), 'the JSON html');
        $this->assertRendersBoldAndLink($this->pageHtml(), 'the article page');
    }

    public function test_guests_cannot_post_comments(): void
    {
        $response = $this->post("/articles/{$this->article->getRouteKey()}/comments", ['body' => 'Guest comment']);

        $this->assertGreaterThanOrEqual(300, $response->status());
        $this->assertLessThan(500, $response->status());
        $this->assertSame(0, DB::table('comments')->count());
    }

    public function test_comments_are_newest_first_fifteen_per_page(): void
    {
        foreach (range(1, 17) as $i) {
            $this->travel(1)->minutes();
            $this->postComment(sprintf('Comment Tie%02dq', $i));
        }

        $first = $this->jsonPage(1);
        $this->assertCount(15, $first);
        $this->assertSame(
            array_map(fn (int $i) => sprintf('Tie%02dq', $i), range(17, 3)),
            $this->markers(implode(' ', array_column($first, 'html'))),
        );

        $this->assertSame(['Tie02q', 'Tie01q'], $this->markers(implode(' ', array_column($this->jsonPage(2), 'html'))));

        $page = $this->pageHtml();
        $onPage = $this->markers($page);
        $this->assertSame(array_map(fn (int $i) => sprintf('Tie%02dq', $i), range(17, 3)), $onPage);
    }

    public function test_walking_the_json_pages_returns_each_same_second_comment_once(): void
    {
        foreach (range(1, 30) as $i) {
            $this->postComment(sprintf('Same second Tie%02dq', $i));
        }

        $seen = [];
        for ($page = 1; $page <= 10; $page++) {
            $items = $this->jsonPage($page);
            if ($items === []) {
                break;
            }
            $this->assertLessThanOrEqual(15, count($items));
            foreach ($items as $item) {
                $seen = [...$seen, ...$this->markers((string) ($item['html'] ?? ''))];
            }
        }

        sort($seen);
        $this->assertSame(array_map(fn (int $i) => sprintf('Tie%02dq', $i), range(1, 30)), $seen);
    }

    public function test_walking_the_article_pages_shows_each_same_second_comment_once(): void
    {
        foreach (range(1, 30) as $i) {
            $this->postComment(sprintf('Same second Tie%02dq', $i));
        }

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $seen = [...$seen, ...$this->markers($this->pageHtml($page))];
        }

        sort($seen);
        $this->assertSame(array_map(fn (int $i) => sprintf('Tie%02dq', $i), range(1, 30)), $seen);
    }
}
