<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    $this->sdk = MockClient::global([]);
    DB::shouldReceive('connection')->never();
});

afterEach(function () {
    $this->sdk->assertNothingSent();
    MockClient::destroyGlobal();
});

it('does nothing without a selected operation', function () {
    $this->artisan('superpdp:collect-invoice-evidence')->assertSuccessful();
});

it('refuses unbound invoice targets before SQL or API', function () {
    $this->artisan('superpdp:collect-invoice-evidence', ['--invoice' => '82', '--diagnose' => true])->assertFailed();
});

it('refuses execution without an approved fingerprint before reading SQL', function () {
    $this->artisan('superpdp:collect-invoice-evidence', ['--execute' => true])->assertFailed();
});

it('refuses ambiguous diagnose and execute modes', function () {
    $this->artisan('superpdp:collect-invoice-evidence', ['--execute' => true, '--diagnose' => true])->assertFailed();
});

it('refuses a non sandbox runtime before reading SQL', function () {
    $this->artisan('superpdp:collect-invoice-evidence', ['--execute' => true, '--source-sha256' => str_repeat('a', 64)])->assertFailed();
});

it('requires an approved fingerprint for the single buyer GET too', function () {
    $this->artisan('superpdp:collect-invoice-evidence', ['--execute' => true, '--buyer-only' => true])->assertFailed();
});

it('refuses seller reuse outside buyer diagnostic mode before SQL or HTTP', function () {
    $this->artisan('superpdp:collect-invoice-evidence', ['--execute' => true,
        '--source-sha256' => str_repeat('a', 64), '--seller-evidence' => 'invalid'])->assertFailed();
});
