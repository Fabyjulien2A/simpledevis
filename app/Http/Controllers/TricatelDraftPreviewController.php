<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\SuperPdp\TricatelDraftDocumentPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TricatelDraftPreviewController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, TricatelDraftDocumentPreview $previews): Response
    {
        $preview = $previews->build($request->user(), $invoice);

        return response()->view('invoices.sandbox-draft-preview', compact('preview'))
            ->header('Cache-Control', 'private, no-store');
    }
}
