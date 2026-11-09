<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceLogoArchive;
use App\Services\InvoiceLogoReference;
use App\Services\InvoicePartySnapshot;
use App\Services\InvoicePdfPartyData;
use App\Services\InvoicePdfRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Parser;
use Tests\Support\InvoiceLogoFixture;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Storage::fake('public');
    Storage::fake('invoice_logos');
    $this->user = User::factory()->create();
    $this->company = $this->user->company()->create(['company_name' => 'Artisan', 'logo' => 'logos/source.png']);
    $this->client = $this->user->clients()->create(['first_name' => 'Marie', 'email' => 'marie@example.test']);
    $this->bytes = InvoiceLogoFixture::bytes();
    Storage::disk('public')->put('logos/source.png', $this->bytes);
    $this->actingAs($this->user);
    $this->payload = ['action' => 'draft', 'french_business_process_type' => 'S1',
        'client_id' => $this->client->id, 'date' => '2026-11-09',
        'items' => [['description' => 'Prestation', 'quantity' => '1', 'unit_price_ht' => '100',
            'tva_rate' => '20', 'unit_code' => 'HUR', 'tax_treatment' => 'taxable']],
    ];
    $this->post(route('invoices.store'), $this->payload)->assertSessionHasNoErrors();
    $this->invoice = Invoice::sole();
});

afterEach(function () {
    Http::assertNothingSent();
});

function issueLogoInvoice($test, string $path = 'draft'): Invoice
{
    if ($path === 'direct') {
        $test->post(route('invoices.store'), ['action' => 'issue'] + $test->payload)->assertSessionHasNoErrors();

        return Invoice::latest('id')->first();
    }
    $test->post(route('invoices.finalize', $test->invoice))->assertSessionHas('success');

    return $test->invoice->fresh();
}

it('archives the actual PNG JPEG or WebP bytes privately before issuing through either path', function (string $format, string $path) {
    $bytes = InvoiceLogoFixture::bytes($format);
    // Extension source volontairement incorrecte : le format provient des octets.
    Storage::disk('public')->put('logos/source.png', $bytes);
    $invoice = issueLogoInvoice($this, $path);
    $snapshot = $invoice->party_snapshot;
    $reference = InvoiceLogoReference::parse($snapshot['seller']['logo']);
    expect($snapshot['schema_version'])->toBe(2)
        ->and($reference->extension)->toBe($format)->and($reference->userId)->toBe((string) $this->user->id)
        ->and($reference->sha256)->toBe(hash('sha256', $bytes))
        ->and(Storage::disk('invoice_logos')->get($reference->path()))->toBe($bytes)
        ->and(Storage::disk('invoice_logos')->allFiles())->toHaveCount(1)
        ->and(Storage::disk('public')->allFiles())->toBe(['logos/source.png'])
        ->and(config('filesystems.disks.invoice_logos.serve'))->toBeFalse();
    $parties = app(InvoicePdfPartyData::class)->build($invoice);
    $pdf = app(InvoicePdfRenderer::class)->generate($invoice)['content'];
    expect($parties['logo_path'])->toBe(realpath(Storage::disk('invoice_logos')->path($reference->path())))
        ->and($pdf)->toStartWith('%PDF-')
        ->and((new Parser)->parseContent($pdf)->getObjectsByType('XObject', 'Image'))->not->toBeEmpty();
    auth()->logout();
    // Les appels sans session utilisent le propriétaire enregistré de la facture.
    expect(app(InvoicePdfPartyData::class)->build($invoice)['logo_path'])->toBe($parties['logo_path']);
})->with(['png', 'jpg', 'webp'])->with(['draft', 'direct']);

it('deduplicates equal content for one owner across uploads and invoices without touching the existing archive', function () {
    $first = issueLogoInvoice($this);
    $reference = $first->party_snapshot['seller']['logo'];
    $archivePath = app(InvoiceLogoArchive::class)->resolve($this->user->id, $reference);
    touch($archivePath, 1000000000);
    Storage::disk('public')->put('logos/another-name.jpg', $this->bytes);
    $this->company->update(['logo' => 'logos/another-name.jpg']);
    $second = issueLogoInvoice($this, 'direct');
    clearstatcache();
    expect($second->party_snapshot['seller']['logo'])->toBe($reference)
        ->and(Storage::disk('invoice_logos')->allFiles())->toHaveCount(1)
        ->and(filemtime($archivePath))->toBe(1000000000);
});

it('separates equal contents between owners and refuses cross-owner references', function () {
    $first = issueLogoInvoice($this);
    $other = User::factory()->create();
    $otherReference = app(InvoiceLogoArchive::class)->archive($other->id, 'logos/source.png');
    expect($otherReference)->not->toBe($first->party_snapshot['seller']['logo'])
        ->and(Storage::disk('invoice_logos')->allFiles())->toHaveCount(2);
    expect(fn () => app(InvoiceLogoArchive::class)->resolve($other->id, $first->party_snapshot['seller']['logo']))
        ->toThrow(ValidationException::class);
    $this->actingAs($other)->get(route('invoices.pdf', $first))->assertForbidden();
    $snapshot = $first->party_snapshot;
    $snapshot['seller']['logo'] = $otherReference;
    DB::table('invoices')->where('id', $first->id)->update(['party_snapshot' => json_encode($snapshot)]);
    $this->actingAs($this->user)->getJson(route('invoices.pdf', $first))->assertUnprocessable()
        ->assertJsonValidationErrors('party_snapshot');
});

it('keeps archived PDFs usable after public logo replacement or deletion', function () {
    $invoice = issueLogoInvoice($this);
    $before = $invoice->getRawOriginal('party_snapshot');
    $path = app(InvoicePdfPartyData::class)->build($invoice)['logo_path'];
    $replacement = UploadedFile::fake()->image('new-logo.png');
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = [$query->sql, DB::transactionLevel()];
    });
    $this->post(route('company.update'), ['logo' => $replacement])->assertSessionHasNoErrors();
    Storage::disk('public')->assertMissing('logos/source.png');
    $lockQueries = collect($queries)->filter(fn ($query) => str_starts_with($query[0], 'select'))->values();
    expect($lockQueries[0][0])->toContain('from "users"')
        ->and($lockQueries[0][1])->toBeGreaterThan(1)
        ->and($lockQueries[1][0])->toContain('from "companies"');
    Storage::disk('public')->delete('logos/source.png');
    $current = $this->company->fresh()->logo;
    Storage::disk('public')->delete($current);
    expect(app(InvoicePdfPartyData::class)->build($invoice)['logo_path'])->toBe($path)
        ->and($invoice->fresh()->getRawOriginal('party_snapshot'))->toBe($before)
        ->and(file_get_contents($path))->toBe($this->bytes)
        ->and(app(InvoicePdfRenderer::class)->generate($invoice)['content'])->toStartWith('%PDF-');
});

it('deletes only the superseded public upload after commit and keeps it if the SQL update rolls back', function (bool $fail) {
    $invoice = issueLogoInvoice($this);
    $archived = $invoice->party_snapshot['seller']['logo'];
    $observed = false;
    Event::listen('eloquent.updated: '.Company::class, function () use (&$observed, $fail) {
        $observed = true;
        expect(DB::transactionLevel())->toBeGreaterThan(1);
        Storage::disk('public')->assertExists('logos/source.png');
        if ($fail) {
            throw new RuntimeException('Company SQL failure');
        }
    });
    if ($fail) {
        $this->withoutExceptionHandling();
        try {
            $this->post(route('company.update'), ['logo' => UploadedFile::fake()->image('replacement.png')]);
            $this->fail('Expected SQL failure');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('Company SQL failure');
        }
        Storage::disk('public')->assertExists('logos/source.png');
        expect($this->company->fresh()->logo)->toBe('logos/source.png');
    } else {
        $this->post(route('company.update'), ['logo' => UploadedFile::fake()->image('replacement.png')])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing('logos/source.png');
    }
    expect($observed)->toBeTrue()
        ->and(file_get_contents(app(InvoiceLogoArchive::class)->resolve($this->user->id, $archived)))->toBe($this->bytes);
})->with([false, true]);

it('keeps the previous source and reference when storing the replacement fails', function () {
    $originalDisk = Storage::disk('public');
    $disk = Mockery::mock($originalDisk);
    $disk->shouldReceive('putFileAs')->andReturn(false);
    Storage::set('public', $disk);
    $this->postJson(route('company.update'), ['logo' => UploadedFile::fake()->image('replacement.png')])
        ->assertUnprocessable()->assertJsonValidationErrors('logo');
    expect($this->company->fresh()->logo)->toBe('logos/source.png')
        ->and($originalDisk->get('logos/source.png'))->toBe($this->bytes);
});

it('refuses unreadable missing invalid or unsafe declared logos without consuming a number', function (string $case, string $path) {
    if ($case === 'missing') {
        Storage::disk('public')->delete('logos/source.png');
    } elseif ($case === 'invalid') {
        Storage::disk('public')->put('logos/source.png', 'not an image');
    } elseif ($case === 'traversal') {
        $this->company->update(['logo' => '../private/secret.png']);
    } else {
        $mock = Mockery::mock(InvoiceLogoArchive::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $mock->shouldReceive('readBytes')->andThrow(new RuntimeException('Read failure'));
        $this->app->instance(InvoiceLogoArchive::class, $mock);
        app('router')->getRoutes()->getByName('invoices.store')->flushController();
    }
    $before = $this->invoice->fresh()->getRawOriginal();
    $response = $path === 'direct'
        ? $this->postJson(route('invoices.store'), ['action' => 'issue'] + $this->payload)
        : $this->postJson(route('invoices.finalize', $this->invoice));
    $response->assertUnprocessable()->assertJsonValidationErrors('logo');
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before)
        ->and(Storage::disk('invoice_logos')->allFiles())->toBe([]);
    $this->assertDatabaseCount('invoices', 1);
    $this->assertDatabaseCount('invoice_counters', 0);
})->with(['missing', 'invalid', 'traversal', 'unreadable'])->with(['draft', 'direct']);

it('rolls back issuing on storage write incomplete-copy or archive reread failures', function (string $failure) {
    $mock = Mockery::mock(InvoiceLogoArchive::class)->makePartial()->shouldAllowMockingProtectedMethods();
    if ($failure === 'write') {
        $mock->shouldReceive('publish')->andThrow(new RuntimeException('Disk full'));
    } elseif ($failure === 'partial') {
        $mock->shouldReceive('publish')->andReturnUsing(fn ($path) => file_put_contents($path, 'partial'));
    } else {
        $mock->shouldReceive('readBytes')->andReturnUsing(function ($path) {
            if (! str_contains($path, 'source.png')) {
                throw new RuntimeException('Archive read failure');
            }

            return file_get_contents($path);
        });
    }
    $this->app->instance(InvoiceLogoArchive::class, $mock);
    $before = $this->invoice->fresh()->getRawOriginal();
    $this->postJson(route('invoices.finalize', $this->invoice))->assertUnprocessable()->assertJsonValidationErrors('logo');
    expect($this->invoice->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('invoice_counters', 0);
})->with(['write', 'partial', 'readback']);

it('leaves a reusable orphan after SQL rollback and never deletes an existing shared archive', function () {
    $fail = true;
    Event::listen('eloquent.updating: '.Invoice::class, function (Invoice $invoice) use (&$fail) {
        if ($fail && $invoice->isDirty('document_status')) {
            throw new RuntimeException('SQL write failure');
        }
    });
    $this->withoutExceptionHandling();
    try {
        $this->post(route('invoices.finalize', $this->invoice));
        $this->fail('Expected SQL failure');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('SQL write failure');
    } finally {
        $fail = false;
    }
    expect($this->invoice->fresh()->party_snapshot)->toBeNull()
        ->and($this->invoice->fresh()->document_status)->toBe('draft')
        ->and(Storage::disk('invoice_logos')->allFiles())->toHaveCount(1);
    $this->assertDatabaseCount('invoice_counters', 0);
    issueLogoInvoice($this);
    expect(Storage::disk('invoice_logos')->allFiles())->toHaveCount(1);
});

it('refuses an absent altered wrong-format or malformed archived reference without fallback or email delivery', function (string $case) {
    $invoice = issueLogoInvoice($this);
    $snapshot = $invoice->party_snapshot;
    $reference = InvoiceLogoReference::parse($snapshot['seller']['logo']);
    if ($case === 'missing') {
        Storage::disk('invoice_logos')->delete($reference->path());
    } elseif ($case === 'altered') {
        Storage::disk('invoice_logos')->put($reference->path(), InvoiceLogoFixture::bytes('png', 200));
    } elseif ($case === 'wrong format') {
        // Même empreinte, mais extension mensongère : le contrôle MIME doit aussi refuser.
        $snapshot['seller']['logo'] = str_replace('.png', '.jpg', $snapshot['seller']['logo']);
        Storage::disk('invoice_logos')->put(str_replace('.png', '.jpg', $reference->path()), $this->bytes);
    } else {
        $snapshot['seller']['logo'] = $case === 'traversal' ? '../public/logos/source.png' : 'invoice-logos/v2/1/invalid.png';
    }
    DB::table('invoices')->where('id', $invoice->id)->update(['party_snapshot' => json_encode($snapshot)]);
    config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
    Mail::purge();
    Pdf::shouldReceive('loadView')->never();
    $this->getJson(route('invoices.pdf', $invoice))->assertUnprocessable()->assertJsonValidationErrors('party_snapshot');
    $this->postJson(route('invoices.sendEmail', $invoice))->assertUnprocessable()->assertJsonValidationErrors('party_snapshot');
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
})->with(['missing', 'altered', 'wrong format', 'traversal', 'unknown reference version']);

it('does not overwrite a corrupt archive when finalizing another invoice with the same content', function () {
    $invoice = issueLogoInvoice($this);
    $reference = InvoiceLogoReference::parse($invoice->party_snapshot['seller']['logo']);
    Storage::disk('invoice_logos')->put($reference->path(), 'corrupted');
    $this->postJson(route('invoices.store'), ['action' => 'issue'] + $this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors('logo');
    expect(Storage::disk('invoice_logos')->get($reference->path()))->toBe('corrupted');
    $this->assertDatabaseCount('invoices', 1);
});

it('allows a new version two invoice without a declared logo and does not create files', function () {
    $this->company->update(['logo' => null]);
    $invoice = issueLogoInvoice($this);
    expect($invoice->party_snapshot['schema_version'])->toBe(2)
        ->and($invoice->party_snapshot['seller']['logo'])->toBeNull()
        ->and(app(InvoicePdfPartyData::class)->build($invoice)['logo_path'])->toBeNull()
        ->and(Storage::disk('invoice_logos')->allFiles())->toBe([]);
});

it('keeps distinct contents for one owner without overwriting the first version', function () {
    $first = issueLogoInvoice($this);
    Storage::disk('public')->put('logos/source.png', InvoiceLogoFixture::bytes('png', 200));
    $second = issueLogoInvoice($this, 'direct');
    expect($second->party_snapshot['seller']['logo'])->not->toBe($first->party_snapshot['seller']['logo'])
        ->and(Storage::disk('invoice_logos')->allFiles())->toHaveCount(2)
        ->and(file_get_contents(app(InvoiceLogoArchive::class)->resolve($this->user->id, $first->party_snapshot['seller']['logo'])))->toBe($this->bytes);
});

it('refuses an exposed or automatically served archive disk before issuing', function (string $case) {
    if ($case === 'served') {
        config(['filesystems.disks.invoice_logos.serve' => true]);
    } else {
        Storage::set('invoice_logos', Storage::disk('public'));
    }
    $this->postJson(route('invoices.finalize', $this->invoice))->assertUnprocessable()->assertJsonValidationErrors('logo');
    expect($this->invoice->fresh()->document_status)->toBe('draft')
        ->and($this->invoice->fresh()->party_snapshot)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe(['logos/source.png']);
})->with(['served', 'public root']);

it('revalidates a previously captured version two draft without rewriting its snapshot', function (bool $missing) {
    $reference = app(InvoiceLogoArchive::class)->archive($this->user->id, 'logos/source.png');
    $snapshot = json_encode(InvoicePartySnapshot::capture(['company_name' => 'Capture précédente', 'logo' => $reference], [], 2));
    DB::table('invoices')->where('id', $this->invoice->id)->update(['party_snapshot' => $snapshot]);
    if ($missing) {
        Storage::disk('invoice_logos')->delete(InvoiceLogoReference::parse($reference)->path());
        $this->postJson(route('invoices.finalize', $this->invoice))->assertUnprocessable()->assertJsonValidationErrors('party_snapshot');
        expect($this->invoice->fresh()->document_status)->toBe('draft');
    } else {
        issueLogoInvoice($this);
    }
    expect($this->invoice->fresh()->getRawOriginal('party_snapshot'))->toBe($snapshot);
})->with([false, true]);
