<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\SupplierInvoice;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function index(): View
    {
        $user = request()->user();
        $company = $user->company;

        /*
         * Factures clients encore à encaisser.
         */
        $customerInvoices = Invoice::query()
            ->with('client')
            ->where('user_id', $user->id)
            ->whereNotNull('due_date')
            ->whereIn(
                'status',
                [
                    'non_payee',
                    'partiellement_payee',
                ]
            )
            ->get()
            ->map(function (Invoice $invoice): array {
                return [
                    'type' => 'customer_invoice',
                    'direction' => 'incoming',

                    'title' =>
                        $invoice->client?->name
                        ?? $invoice->client?->company_name
                        ?? 'Client non renseigné',

                    'number' => $invoice->invoice_number,
                    'due_date' => $invoice->due_date,
                    'amount' => (float) $invoice->remaining_amount,
                    'status' => $invoice->status,
                    'status_label' => $invoice->payment_status_label,

                    'url' => route(
                        'invoices.show',
                        $invoice
                    ),
                ];
            });

        /*
         * Factures fournisseurs encore à payer.
         */
        $supplierInvoices = collect();

        if ($company) {
            $supplierInvoices = SupplierInvoice::query()
                ->where('company_id', $company->id)
                ->whereNotNull('due_date')
                ->whereIn(
                    'payment_status',
                    [
                        'unpaid',
                        'partial',
                    ]
                )
                ->get()
                ->map(function (SupplierInvoice $invoice): array {
                    return [
                        'type' => 'supplier_invoice',
                        'direction' => 'outgoing',

                        'title' =>
                            $invoice->supplier_name
                            ?: 'Fournisseur non renseigné',

                        'number' => $invoice->invoice_number,
                        'due_date' => $invoice->due_date,

                        'amount' => (float) (
                            $invoice->remaining_amount
                            ?? $invoice->total_ttc
                        ),

                        'status' => $invoice->payment_status,
                        'status_label' =>
                            $invoice->paymentStatusLabel(),

                        'url' => route(
                            'supplier-invoices.show',
                            $invoice
                        ),
                    ];
                });
        }

        /*
         * Une seule chronologie, triée par date d’échéance.
         */
        $scheduleItems = $customerInvoices
            ->concat($supplierInvoices)
            ->sortBy('due_date')
            ->values();

        /*
         * Groupes d’affichage.
         */
        $overdueItems = $scheduleItems
            ->filter(
                fn (array $item): bool =>
                    $item['due_date']->isBefore(today())
            )
            ->values();

        $todayItems = $scheduleItems
            ->filter(
                fn (array $item): bool =>
                    $item['due_date']->isToday()
            )
            ->values();

        $nextSevenDaysItems = $scheduleItems
            ->filter(
                fn (array $item): bool =>
                    $item['due_date']->isAfter(today())
                    && $item['due_date']->lte(
                        today()->addDays(7)
                    )
            )
            ->values();

        $laterItems = $scheduleItems
            ->filter(
                fn (array $item): bool =>
                    $item['due_date']->gt(
                        today()->addDays(7)
                    )
            )
            ->values();

        /*
         * Totaux utiles pour le bandeau supérieur.
         */
        $incomingAmount = $scheduleItems
            ->where('direction', 'incoming')
            ->sum('amount');

        $outgoingAmount = $scheduleItems
            ->where('direction', 'outgoing')
            ->sum('amount');

        $forecastBalance =
            $incomingAmount - $outgoingAmount;

        return view(
            'schedule.index',
            compact(
                'scheduleItems',
                'overdueItems',
                'todayItems',
                'nextSevenDaysItems',
                'laterItems',
                'incomingAmount',
                'outgoingAmount',
                'forecastBalance'
            )
        );
    }
}