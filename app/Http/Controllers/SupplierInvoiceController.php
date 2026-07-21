<?php

namespace App\Http\Controllers;

use App\Models\SupplierInvoice;
use Bluerock\SuperPdp\SuperPdpConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Throwable;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class SupplierInvoiceController extends Controller
{
    /**
     * Affiche la liste des factures reçues.
     */
    public function index(): View
{
    $company = request()->user()->company;

    abort_unless(
        $company,
        404,
        'Aucune entreprise associée à ce compte.'
    );

    $search = trim(
        (string) request('search')
    );

    $paymentStatus = request('payment_status');

    $invoices = SupplierInvoice::query()
        ->where('company_id', $company->id)

        ->when(
            $search !== '',
            function ($query) use ($search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where(
                            'supplier_name',
                            'like',
                            '%' . $search . '%'
                        )
                        ->orWhere(
                            'invoice_number',
                            'like',
                            '%' . $search . '%'
                        )
                        ->orWhere(
                            'supplier_siren',
                            'like',
                            '%' . $search . '%'
                        );
                });
            }
        )

        ->when(
            in_array(
                $paymentStatus,
                ['unpaid', 'partial', 'paid'],
                true
            ),
            fn ($query) =>
                $query->where(
                    'payment_status',
                    $paymentStatus
                )
        )

        ->latest('received_at')
        ->paginate(15)
        ->withQueryString();

    return view(
        'supplier-invoices.index',
        compact('invoices')
    );
}

    /**
     * Affiche le détail d’une facture reçue.
     */
    public function show(
        SupplierInvoice $supplierInvoice
    ): View {
        $this->authorizeCompanyInvoice($supplierInvoice);

        $supplierInvoice->load([
            'items',
            'events',
            'payments',
        ]);

        return view(
            'supplier-invoices.show',
            compact('supplierInvoice')
        );
    }

    /**
 * Enregistre un paiement sur une facture fournisseur.
 */
public function storePayment(
    Request $request,
    SupplierInvoice $supplierInvoice
): RedirectResponse {
    $this->authorizeCompanyInvoice($supplierInvoice);

    $validated = $request->validate([
        'amount' => ['required', 'numeric', 'min:0.01'],
        'paid_at' => ['required', 'date'],
        'method' => ['required', 'string', 'max:50'],
        'reference' => ['nullable', 'string', 'max:255'],
        'notes' => ['nullable', 'string', 'max:2000'],
    ]);

    $alreadyPaid = (float) $supplierInvoice
        ->payments()
        ->sum('amount');

    $remainingBeforePayment = max(
        0,
        (float) $supplierInvoice->total_ttc - $alreadyPaid
    );

    if ((float) $validated['amount'] > $remainingBeforePayment) {
        return redirect()
            ->route('supplier-invoices.show', $supplierInvoice)
            ->withErrors([
                'amount' =>
                    'Le montant saisi dépasse le reste à payer de '
                    . number_format(
                        $remainingBeforePayment,
                        2,
                        ',',
                        ' '
                    )
                    . ' €.',
            ])
            ->withInput();
    }

    $supplierInvoice->payments()->create($validated);

    $totalPaid = (float) $supplierInvoice
        ->payments()
        ->sum('amount');

    $remainingAmount = max(
        0,
        (float) $supplierInvoice->total_ttc - $totalPaid
    );

    $paymentStatus = match (true) {
        $totalPaid <= 0 => 'unpaid',
        $remainingAmount > 0 => 'partial',
        default => 'paid',
    };

    $supplierInvoice->update([
        'paid_amount' => $totalPaid,
        'remaining_amount' => $remainingAmount,
        'payment_status' => $paymentStatus,
    ]);

    return redirect()
        ->route('supplier-invoices.show', $supplierInvoice)
        ->with(
            'success',
            'Le paiement fournisseur a bien été enregistré.'
        );
}

    /**
 * Génère le PDF de la facture fournisseur.
 */
public function downloadPdf(
    SupplierInvoice $supplierInvoice
): Response
{
    $this->authorizeCompanyInvoice($supplierInvoice);

    $supplierInvoice->load([
        'items',
        'events',
    ]);

    $company = request()->user()->company;

    $pdf = Pdf::loadView(
        'supplier-invoices.pdf',
        [
            'supplierInvoice' => $supplierInvoice,
            'company' => $company,
        ]
    );

    $filename = $this->downloadFilename(
        $supplierInvoice,
        'pdf'
    );

    return $pdf->download($filename);
}

    /**
     * Télécharge les données brutes SUPER PDP au format JSON.
     */
    public function downloadJson(
        SupplierInvoice $supplierInvoice
    ): Response {
        $this->authorizeCompanyInvoice($supplierInvoice);

        $filename = $this->downloadFilename(
            $supplierInvoice,
            'json'
        );

        $json = json_encode(
            $supplierInvoice->payload ?? [],
            JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        abort_if(
            $json === false,
            500,
            'Impossible de générer le fichier JSON.'
        );

        return response(
            $json,
            200,
            [
                'Content-Type' =>
                    'application/json; charset=UTF-8',

                'Content-Disposition' =>
                    'attachment; filename="' . $filename . '"',
            ]
        );
    }

    /**
     * Télécharge le document électronique original depuis SUPER PDP.
     *
     * SUPER PDP peut retourner un fichier XML ou un PDF Factur-X.
     */
    public function downloadXml(
        SupplierInvoice $supplierInvoice
    ): Response|RedirectResponse {
        $this->authorizeCompanyInvoice($supplierInvoice);

        $company = request()->user()->company;
        $connection = $company->superPdpConnection;

        if (
            !$connection
            || $connection->status !== 'connected'
            || !$connection->access_token
        ) {
            return redirect()
                ->route(
                    'supplier-invoices.show',
                    $supplierInvoice
                )
                ->with(
                    'error',
                    'La connexion à SUPER PDP n’est pas disponible.'
                );
        }

        if (!$supplierInvoice->superpdp_invoice_id) {
            return redirect()
                ->route(
                    'supplier-invoices.show',
                    $supplierInvoice
                )
                ->with(
                    'error',
                    'Cette facture ne possède pas d’identifiant SUPER PDP.'
                );
        }

        $apiUrl = rtrim(
            (string) config('services.superpdp.api_url'),
            '/'
        );

        if ($apiUrl === '') {
            return redirect()
                ->route(
                    'supplier-invoices.show',
                    $supplierInvoice
                )
                ->with(
                    'error',
                    'L’URL de l’API SUPER PDP n’est pas configurée.'
                );
        }

        try {
            /*
             * Il est indispensable de transmettre l’URL configurée.
             *
             * Sans baseUrl, le SDK utilise l’environnement
             * de production par défaut, même si le jeton provient
             * du bac à sable.
             */
            $api = SuperPdpConnector::withToken(
                accessToken: $connection->access_token,
                baseUrl: $apiUrl
            );

            $superPdpResponse = $api
                ->invoice()
                ->download(
                    id: (int) $supplierInvoice
                        ->superpdp_invoice_id
                );

            if ($superPdpResponse->failed()) {
                $status = $superPdpResponse->status();
                $body = $superPdpResponse->body();

                logger()->warning(
                    'Échec du téléchargement SUPER PDP.',
                    [
                        'supplier_invoice_id' =>
                            $supplierInvoice->id,

                        'superpdp_invoice_id' =>
                            $supplierInvoice
                                ->superpdp_invoice_id,

                        'status' => $status,
                        'response' => $body,
                        'api_url' => $apiUrl,
                    ]
                );

                $message = match ($status) {
                    401 =>
                        'Le jeton SUPER PDP est expiré ou ne correspond pas à cet environnement.',

                    403 =>
                        'SUPER PDP ne vous autorise pas à télécharger ce document.',

                        404 =>
      'Aucun document original n’est associé à cette facture dans SUPER PDP.',

                    default =>
                        'SUPER PDP a refusé le téléchargement du document.',
                };

                return redirect()
                    ->route(
                        'supplier-invoices.show',
                        $supplierInvoice
                    )
                    ->with('error', $message);
            }

            $content = $superPdpResponse->body();

            if ($content === '') {
                return redirect()
                    ->route(
                        'supplier-invoices.show',
                        $supplierInvoice
                    )
                    ->with(
                        'error',
                        'SUPER PDP a retourné un document vide.'
                    );
            }

            /*
             * Un fichier PDF commence normalement par "%PDF".
             * Sinon, on considère que le document est un XML.
             */
            $isPdf = str_starts_with(
                ltrim($content),
                '%PDF'
            );

            $extension = $isPdf
                ? 'pdf'
                : 'xml';

            $contentType = $isPdf
                ? 'application/pdf'
                : 'application/xml; charset=UTF-8';

            $filename = $this->downloadFilename(
                $supplierInvoice,
                $extension
            );

            return response(
                $content,
                200,
                [
                    'Content-Type' => $contentType,

                    'Content-Disposition' =>
                        'attachment; filename="' .
                        $filename .
                        '"',

                    'Content-Length' =>
                        (string) strlen($content),
                ]
            );
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route(
                    'supplier-invoices.show',
                    $supplierInvoice
                )
                ->with(
                    'error',
                    'Impossible de télécharger le document depuis SUPER PDP.'
                );
        }
    }

    /**
     * Vérifie que la facture appartient à l’entreprise connectée.
     */
    private function authorizeCompanyInvoice(
        SupplierInvoice $supplierInvoice
    ): void {
        $company = request()->user()->company;

        abort_unless(
            $company,
            404,
            'Aucune entreprise associée à ce compte.'
        );

        abort_unless(
            $supplierInvoice->company_id === $company->id,
            403,
            'Vous n’êtes pas autorisé à accéder à cette facture.'
        );
    }

    /**
     * Génère un nom de fichier sûr pour le téléchargement.
     */
    private function downloadFilename(
        SupplierInvoice $supplierInvoice,
        string $extension
    ): string {
        $invoiceNumber = preg_replace(
            '/[^A-Za-z0-9_-]+/',
            '-',
            $supplierInvoice->invoice_number
        );

        $invoiceNumber = trim(
            $invoiceNumber ?: 'facture',
            '-'
        );

        return 'facture-' .
            $invoiceNumber .
            '.' .
            $extension;
    }
}