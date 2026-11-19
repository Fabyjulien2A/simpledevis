<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\SuperPdp\CiiPreparationRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class InvoiceCiiDownloadController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, string $reservation, CiiPreparationRegistry $registry): Response
    {
        abort_unless($invoice->user_id === $request->user()->id, 403);
        $invoice = $invoice->newQuery()->findOrFail($invoice->id);
        abort_unless($invoice->user_id === $request->user()->id, 403);
        abort_unless($invoice->isIssued(), 404);
        try {
            $result = $registry->consult($request->user(), $invoice, $reservation, true);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable) {
            return response('Le document CII est absent, incompatible ou son intégrité ne peut pas être vérifiée.', 409)
                ->withHeaders(['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        $document = $result['result']['document'] ?? null;
        if (! is_string($document) || $document === '') {
            return response('Aucun document CII vérifié disponible.', 404)->header('Cache-Control', 'private, no-store');
        }
        $validation = $result['result']['artifact']['validation_status'];
        $suffix = $result['result']['status'] === 'prepared_simulation' ? 'simulation'
            : ($result['result']['status'] === 'prepared_validated' && $validation === 'passed' ? 'valide' : 'non-valide');

        return response($document)->withHeaders([
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="facture-'.$invoice->id.'-cii-'.$suffix.'.xml"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
