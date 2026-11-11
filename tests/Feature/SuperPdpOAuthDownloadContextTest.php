<?php

use App\Models\User;
use Bluerock\SuperPdp\Requests\Invoices\DownloadInvoiceRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Saloon\Config;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    config(['services.superpdp.environment' => 'sandbox', 'services.superpdp.client_id' => 'test-client',
        'services.superpdp.api_url' => 'https://superpdp.test/v1.beta']);
    Http::preventStrayRequests();
    Config::preventStrayRequests();
    $user = User::factory()->create();
    $company = $user->company()->create(['company_name' => 'Reception']);
    $this->actingAs($user);
    $this->connection = $company->superPdpConnection()->create([
        'environment' => 'sandbox', 'oauth_client_id' => 'test-client', 'api_url' => config('services.superpdp.api_url'),
        'access_token' => 'SECRET-TOKEN', 'refresh_token' => 'SECRET-REFRESH', 'status' => 'connected',
    ]);
    $this->invoice = $company->supplierInvoices()->create([
        'superpdp_invoice_id' => 123, 'invoice_number' => 'RECEIVED-123', 'invoice_date' => '2026-11-10',
        'supplier_name' => 'Supplier', 'total_ht' => '100', 'total_vat' => '20', 'total_ttc' => '120',
    ]);
});

afterEach(function () {
    MockClient::destroyGlobal();
    Config::allowStrayRequests();
    Http::assertNothingSent();
});

it('preserves original document downloading with a qualified token and mock SDK response', function () {
    $mock = MockClient::global([DownloadInvoiceRequest::class => MockResponse::make('<Invoice>original</Invoice>', 200)]);
    $this->get(route('supplier-invoices.xml', $this->invoice))->assertOk()->assertContent('<Invoice>original</Invoice>');
    $mock->assertSentCount(1);
});

it('refuses crossed or unknown contexts before the SDK can send the token', function (?string $environment) {
    $this->connection->update(['environment' => $environment]);
    $mock = MockClient::global([]);
    $this->get(route('supplier-invoices.xml', $this->invoice))->assertRedirect(route('supplier-invoices.show', $this->invoice))->assertSessionHas('error');
    $mock->assertSentCount(0);
})->with([null, 'production']);

it('logs only local identifiers when downloading fails', function () {
    Log::spy();
    MockClient::global([DownloadInvoiceRequest::class => MockResponse::make('REMOTE-SECRET', 400)]);
    $this->get(route('supplier-invoices.xml', $this->invoice))->assertSessionHas('error');
    Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) {
        return ! str_contains(json_encode([$message, $context]), 'SECRET') && ! isset($context['response']);
    });
});
