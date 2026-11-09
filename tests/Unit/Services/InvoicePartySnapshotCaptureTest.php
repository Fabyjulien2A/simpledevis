<?php

use App\Models\Invoice;
use App\Services\InvoicePartySnapshotCapture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('refuses capture outside a transaction before any query or network call', function () {
    Http::preventStrayRequests();
    DB::connection()->beforeExecuting(function () {
        throw new RuntimeException('Aucune requête attendue hors transaction.');
    });
    expect(DB::transactionLevel())->toBe(0);
    expect(fn () => app(InvoicePartySnapshotCapture::class)->captureForFinalization(new Invoice))
        ->toThrow(LogicException::class, 'exige une transaction');
    Http::assertNothingSent();
});
