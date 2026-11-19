<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\SuperPdp\InvoiceFinalizationSummary;
use App\Services\SuperPdp\SandboxInvoiceProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InvoiceFinalizationSummaryController extends Controller
{
    public function __invoke(Request $request, InvoiceFinalizationSummary $summaries): View
    {
        $input = $request->validate([
            'emission_profile' => ['nullable', Rule::in(SandboxInvoiceProfile::profiles())],
            'invoice_id' => ['nullable', 'integer'], 'client_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'], 'due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'french_business_process_type' => ['nullable', 'string', 'max:10'],
            'items' => ['nullable', 'array', 'max:200'], 'items.*' => ['array'],
        ]);
        $draft = null;
        if (isset($input['invoice_id'])) {
            $draft = Invoice::findOrFail($input['invoice_id']);
            abort_unless($draft->user_id === $request->user()->id, 403);
            abort_unless($draft->isDraft(), 403);
        }
        $client = isset($input['client_id']) ? Client::where('user_id', $request->user()->id)->findOrFail($input['client_id']) : null;
        $summary = $summaries->build($request->user()->company()->first(), $client, $input, $draft);

        return view(($summary['sandbox'] ?? false)
            ? 'invoices.partials.sandbox-finalization-summary'
            : 'invoices.partials.finalization-summary', compact('summary', 'client'));
    }
}
