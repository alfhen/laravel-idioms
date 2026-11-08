# Files and uploads

An upload is hostile input: its name, extension, MIME type, size and contents all come from the client. Validate, store under a name you generate, serve only through an authorized route, and parse defensively.

## Validating the upload

```php
// bad: any type passes; a CSV sniffed as text/plain is rejected
'file' => ['required', 'file', 'max:10240'],
'file' => ['required', 'file', 'mimes:csv', 'max:5120'],

// good
'document' => ['required', File::types(['pdf', 'png', 'jpg'])->max('5mb')],
'import'   => ['required', File::types(['csv', 'txt'])->extensions(['csv', 'txt'])->max('5mb')],
'logo'     => ['required', File::image()->max('2mb')->dimensions(Rule::dimensions()->maxWidth(2000)->maxHeight(2000))],
```
- Every upload gets `file`, a type allow-list and a size cap. A bare `file` accepts anything, and a parser picked from the client's extension falls through to a default for unknown types.
- `mimes` checks the extension guessed from the file's content (`guessExtension()`), `mimetypes` the sniffed MIME type, `extensions` only the client's extension (spoofable). `extensions` is a cheap second check, not a security control. Pair it with `mimes`.
- A CSV can sniff as `text/plain`: header-only, single-column, tab or semicolon files (common from European spreadsheets) often do, and the result depends on the libmagic version. Always allow `txt` next to `csv`: `File::types(['csv', 'txt'])`, never `csv` alone.
- `File::types()` splits entries on `/`. Entries with a slash become `mimetypes:`, the rest `mimes:`, and **both** rules must pass. `File::types(['csv', 'text/csv'])` is `mimes:csv` AND `mimetypes:text/csv`, so a `text/plain` CSV fails both. Don't mix the two forms.
- Size units differ. The string rule `max:5120` is KB. `File::...->max('5mb')` converts with decimal units (1 mb = 1000 KB), so `max('5mb')` is 5000 KB. Also check `upload_max_filesize` and `post_max_size` in `php.ini`: an upload over them arrives as an invalid file and fails every file rule.
- `File::image()` allows jpg, jpeg, png, gif, bmp, webp, avif, heic and heif, and **excludes SVG**. Pass `allowSvg: true` only when you serve the file with `Content-Disposition: attachment` or sanitise it, because an SVG served inline from your origin is stored XSS. The `dimensions` rule returns true for SVG, so it is no size check for them.
- The `php`, `php3` to `php8`, `phtml` and `phar` client extensions are rejected by `mimes` and `extensions` unless `php` is listed. Don't rely on that: the stored name rule below is what protects you.

## Storing

```php
// bad: the client chooses the name, the extension and the directory
$request->file('document')->storeAs('docs', $request->file('document')->getClientOriginalName());
Storage::putFileAs('uploads', $file, "{$uuid}.{$file->getClientOriginalExtension()}");

// good: random name, extension from the content, original name kept for display only
$path = $request->file('document')->store('documents', 'local');   // name the private disk; throw=false, so it can return false
abort_if($path === false, 503);
$request->user()->documents()->create([
    'path' => $path,
    'original_name' => $request->file('document')->getClientOriginalName(),
]);
```
- `store()` and `putFile()` use `hashName()`: 40 random characters plus the extension guessed from content. Never build a stored name or directory from `getClientOriginalName()`, `getClientOriginalExtension()` or a request field. `extension()` can return null, so a hand-built `"{$uuid}.{$ext}"` needs a fallback.
- Keep the client's name in a column for display and for the download name (see Serving).
- Private disk by default. The skeleton's `local` disk is `storage/app/private`. Use `public` only for files every anonymous visitor may read (avatars, product images), and then store only the path.
- With `'throw' => false` (the skeleton's `local` and `public` disks) a failed write is swallowed. `put()` returns `false`, `store()`/`putFile()` return `false` instead of a path, and `get()`/`readStream()` return `null`. Check `=== false` / `=== null` before saving the path or parsing. Don't compare `readStream()` with `false`. Use `'throw' => true` on a disk that jobs and imports depend on, so failures raise.
- `put($path, $resource)` does not close the resource: `fclose()` it yourself. `putFile()` and `putFileAs()` close their own stream.

## Serving

```php
// bad: the path comes from the request (IDOR and traversal); a missing file is a 500
return Storage::download($request->query('path'));
return response()->download(storage_path('app/exports/'.$request->query('f')));

// good: authorize the row, take the path from the database
public function __invoke(Document $document): StreamedResponse
{
    Gate::authorize('view', $document);
    abort_unless(Storage::disk('local')->exists($document->path), 404);

    return Storage::disk('local')->download($document->path, (Str::slug($document->title) ?: 'document').'.pdf');
}
```
- Authorize the model, not the path. Never let the request carry a storage path. `response()->download()` and `response()->file()` take an absolute filesystem path with no root, so request input in them is path traversal. A Flysystem disk is rooted and throws `PathTraversalDetected` (a 500) for `../`, but that does not stop one user reading another's file.
- `Storage::download()` and `Storage::response()` throw on a missing file even when the disk has `throw => false`, so check `exists()` and abort 404 first. A purged blob behind a live row is a 500 otherwise.
- Build the download name from a slug or a fixed pattern. A `/` or `\` in the name makes Symfony throw, and an emoji-only title slugs to an empty string, hence `?: 'document'`. Never hand-build a `Content-Disposition` header.
- Expiring links:
```php
$url = URL::temporarySignedRoute('invoices.download', now()->addMinutes(15), ['invoice' => $invoice]);
Route::get('/invoices/{invoice}/download', DownloadInvoice::class)->name('invoices.download')->middleware('signed');
```
- `signedRoute()` without an expiry never expires: use `temporarySignedRoute()`. A signature is a bearer token. Add `auth` and a policy check unless anyone holding the link may fetch the file.
- A signature covers the path and query string only. Read signed values with `$request->query('plan')`, never `input()`, `all()` or a dynamic property, because a JSON body on a GET overrides the query in merged input.
- An absolute signature includes scheme and host. Behind a proxy that changes either, configure trusted proxies, or sign with `absolute: false` and use `signed:relative`.
- `Storage::temporaryUrl($path, now()->addMinutes(5))` works on S3 and S3-compatible disks, and on a local disk with `serve => true` (a signed `storage.local` route). Other drivers (ftp, sftp) and a local disk without `serve` throw `RuntimeException` unless `buildTemporaryUrlsUsing()` is registered. Guard with `Storage::providesTemporaryUrls()` or register the callback. Don't branch on `app()->isLocal()` to fall back to an unsigned `url()`.

## CSV import

```php
// bad: BOM glued to the first header, blank lines and ragged rows crash, `0` ends the loop, handle leaks
$rows = array_map('str_getcsv', explode("\n", $file->get()));
while ($line = fgets($h)) { /* ... */ }
$data = array_combine($header, $row);

// good: one reader class (App\Imports\CsvReader), lazy, closes its handle
public function rows(string $disk, string $path): LazyCollection
{
    return LazyCollection::make(function () use ($disk, $path) {
        $handle = Storage::disk($disk)->readStream($path);
        throw_if($handle === null, new RuntimeException("Missing {$path}"));

        try {
            $first = preg_replace('/^\xEF\xBB\xBF/', '', (string) fgets($handle));   // no rewind: S3 streams can't seek
            $header = array_map(fn ($h) => mb_strtolower(trim($h)), str_getcsv(rtrim($first, "\r\n"), ',', '"', ''));
            $line = 1;

            while (($text = fgets($handle)) !== false) {   // one physical line per row: line numbers stay true
                $line++;
                if (trim($text) === '') {
                    continue;
                }
                $row = str_getcsv(rtrim($text, "\r\n"), ',', '"', '');
                yield $line => count($row) === count($header) ? array_combine($header, $row) : null;
            }
        } finally {
            fclose($handle);
        }
    });
}
```
- Read a stream (`Storage::readStream()`, `fopen`, `SplFileObject`), never the whole file. When fields never contain line breaks (SKU, stock and price feeds), parse each physical line with `str_getcsv()` as above: line numbers stay physical, and an unclosed quote breaks one row instead of swallowing the rest of the file. When a field can hold line breaks (free-text notes), use `fgetcsv()` and count the newlines inside each record to keep the line number right. Never `str_getcsv($whole, "\n")` or `explode("\n")` on the whole file.
- Signature: `fgetcsv($stream, ?int $length, string $separator, string $enclosure, string $escape)`. Pass `''` as `$escape`: the backslash default is deprecated in PHP 8.4 and mangles `\"`. If users export with semicolons, read the first line and pick the delimiter, or document one.
- Strip a UTF-8 BOM from the start, then normalise headers: trimmed, lower-cased, optionally through an alias map. Look columns up by name, never by position. Check required headers once, up front, and fail the whole file with a 422 on `file` that names the missing ones. Duplicate headers collapse in `array_combine`, so reject them.
- Blank lines come back as `[null]`: skip them. A short or long row makes `array_combine` throw `ValueError`: compare `count($row)` with `count($header)` and report the row instead.
- Validate each row with `Validator::make($data, $rules)`, collect `['line' => $line, 'message' => ...]` and carry on. Import the good rows unless the brief says all-or-nothing (then wrap in `DB::transaction()` and roll back on the first error). `$line` counts records. A quoted cell with a newline makes the record number differ from the file's physical line, so track physical lines (`SplFileObject::key()`) only when exact numbers matter.
- Loop with `!== false`, never `while ($line = fgets($h))`: a final line of `0` with no trailing newline is falsy and is dropped.
- A generator's `finally` runs when the `LazyCollection` finishes or is abandoned, so `fclose()` goes there. Use `->chunk(500)` and write each chunk with `upsert()` instead of row by row. Don't use `file()` or `Storage::get()` for an upload of megabytes.
- Large or slow import: store the file, create an `imports` row, dispatch a job with the row id, read from `Storage::disk($import->disk)->readStream($import->path)` in the job, and delete the file from that disk when the job finishes or fails. When workers run on other hosts or containers, that disk is shared storage (a private S3 bucket), not `local`.
```php
// config/filesystems.php: 'imports_disk' => env('IMPORTS_DISK', 'local'), a private shared disk (S3) when workers run on other hosts
$disk = config('filesystems.imports_disk', 'local');
$path = $request->file('import')->store('imports', $disk);
abort_if($path === false, 503, 'Upload could not be stored.');   // a disk with throw=false returns false instead of throwing
$import = Import::create(['disk' => $disk, 'path' => $path, 'user_id' => $request->user()->id]);
ImportStock::dispatch($import->id);   // never the UploadedFile: PHP deletes its temp file when the request ends
```
- A small import that finishes inside the request may read `$file->getRealPath()` directly.

## CSV export

```php
// bad: builds the whole file in memory, raw user text in the name and in cells
return response($this->buildCsv($customers), 200, ['Content-Disposition' => "attachment; filename={$name}.csv"]);

// good
return response()->streamDownload(function () use ($team) {
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // Excel needs the BOM for UTF-8
    fputcsv($out, ['Name', 'Balance', 'Notes'], ',', '"', '');

    $team->customers()->lazyById(1000)->each(fn ($c) => fputcsv(
        $out, [$this->text($c->name), $c->balance, $this->text($c->notes)], ',', '"', '',
    ));

    fclose($out);
}, 'customers.csv', ['Content-Type' => 'text/csv']);

private function text(?string $v): ?string   // free-text columns only
{
    return $v !== null && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
}
```
- Stream with `streamDownload()` and `lazyById()` (or `cursor()`). An exception inside the callback is rethrown as `StreamedResponseException`, after headers are sent. A few hundred rows may stay in memory. Exports that run for minutes are a queued job that writes to a private disk and notifies with a temporary URL.
- Formula injection: `fputcsv` quotes for CSV but a spreadsheet still evaluates a cell starting with `=`, `+`, `-`, `@`, tab or CR (`=HYPERLINK(...)`). Prefix `'` on **user-controlled text columns only** (names, notes, titles). Don't apply it to numeric columns: a decimal cast returns `-5.00` as a string, and a blanket `is_string` filter corrupts it. Skip it for exports a program reads.
- Name the file from a slug or a fixed string (see Serving).

## Temp files and archives

```php
$tmp = tempnam(sys_get_temp_dir(), 'report');
try {
    $writer->save($tmp);
    Storage::putFileAs('reports', new File($tmp), "{$report->id}.xlsx");   // Illuminate\Http\File; closes its own stream
} finally {
    @unlink($tmp);
}
```
- Create temp files with `tempnam()` or `php://temp`, and `unlink` in `finally`: a plain `unlink` after the work leaks the file whenever the work throws, and a long-lived worker fills the disk. `Http::sink($tmp)` for downloads, `response()->download($path)->deleteFileAfterSend()` for generated files you serve.
- Zip-slip: never extract an archive from a user with `extractTo()` into a shared path and never build a path from an entry name. Extract into a fresh `mkdir` directory removed in `finally`. Reject any entry whose name starts with `/`, contains `..` or a drive prefix, or whose resolved path leaves the directory. Better: read each entry with `getStream($name)` and write it under a name you generate. Cap the entry count and the total uncompressed size (`statIndex($i)['size']`) against zip bombs.

## Tests

```php
it('stores the document under a hashed name on the private disk', function () {
    Storage::fake('local');   // fakes only this disk; no argument fakes the default disk

    $this->actingAs(User::factory()->create())
        ->postJson('/api/documents', ['document' => UploadedFile::fake()->create('Q3 report.pdf', 100)])
        ->assertCreated();

    $document = Document::sole();
    Storage::disk('local')->assertExists($document->path);
    expect($document->path)->toMatch('/^documents\/[A-Za-z0-9]{40}\.pdf$/')
        ->and($document->original_name)->toBe('Q3 report.pdf');
});
```
- `Storage::fake('<the disk the code writes to>')` before the request. It fakes one disk, clears its fake root on every call, and replaces `temporaryUrl()` with an **unsigned** `?expiration=` URL. Test signature, expiry and tampering on the signed route (a `temporarySignedRoute()` URL plus `travel()`), never through the fake.
- Assert the path the code persisted (`Storage::disk('x')->assertExists($model->path)`), not the client's name, and that the stored name is not the client's.
- `UploadedFile::fake()` derives the MIME type from the file **name** (`MimeType::from($name)`). A fake `a.csv` always passes `mimes:csv` and a fake `evil.php` is rejected by its name. Fakes prove name, extension and size rules only (`create('x.pdf', $kb)` for size, `image('a.jpg', 100, 100)` with ext-gd for dimensions).
- To test content sniffing (a PNG named `a.csv`, a real semicolon CSV) use a real file: `new Illuminate\Http\UploadedFile($tmpPath, 'a.csv', null, null, true)` (test mode). For CSV rows use `UploadedFile::fake()->createWithContent('stock.csv', $content)` with a BOM, CRLF, ragged rows, a blank line and a last line of `0`.
- Read a streamed export with `$response->streamedContent()`. Test the 404 for a missing blob, the 403 for a non-owner, and the 403 for an expired or tampered signature.

Evidence: `Illuminate/Validation/Rules/{File,ImageFile,Dimensions}.php`, `Validation/Concerns/ValidatesAttributes.php` (`validateMimes`, `validateExtensions`, `validateImage`, `validateDimensions`, `shouldBlockPhpUpload`), `Http/{FileHelpers,UploadedFile}.php`, `Http/Testing/{File,FileFactory}.php`, `Filesystem/{FilesystemAdapter,LocalFilesystemAdapter,ServeFile}.php`, `Support/Facades/Storage.php`, `Routing/{ResponseFactory,UrlGenerator}.php`, `Routing/Middleware/ValidateSignature.php`, `Database/Concerns/BuildsQueries.php` (`lazyById`), `Testing/TestResponse.php` (`streamedContent`); `laravel/docs/validation.md`, `filesystem.md`, `urls.md`, `http-tests.md`, `queues.md`; laravel/laravel `config/filesystems.php`.
