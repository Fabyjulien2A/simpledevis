<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\SupplierInvoice;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $userId = $user->id;
        $company = $user->company;

        /*
         * Statistiques commerciales.
         */
        $clientsCount = Client::where('user_id', $userId)
            ->count();

        $quotesCount = Quote::where('user_id', $userId)
            ->count();

        $invoicesCount = Invoice::where('user_id', $userId)
            ->count();

        $monthlyRevenue = Invoice::where('user_id', $userId)
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->sum('total_ttc');

        $amountCollected = Payment::whereHas(
            'invoice',
            function ($query) use ($userId) {
                $query->where('user_id', $userId);
            }
        )->sum('amount');

        /*
         * Le reste à encaisser peut être calculé par un attribut
         * du modèle Invoice. On conserve donc la collection.
         */
        $amountToCollect = Invoice::where('user_id', $userId)
            ->get()
            ->sum(function (Invoice $invoice) {
                return (float) $invoice->remaining_amount;
            });

        $overdueInvoicesCount = Invoice::where('user_id', $userId)
            ->where('status', '!=', 'payee')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->count();

        $unpaidInvoicesCount = Invoice::where('user_id', $userId)
            ->whereIn(
                'status',
                [
                    'non_payee',
                    'partiellement_payee',
                ]
            )
            ->count();

        /*
         * Derniers devis et dernières factures clients.
         */
        $recentQuotes = Quote::where('user_id', $userId)
            ->latest()
            ->take(5)
            ->get();

        $recentInvoices = Invoice::where('user_id', $userId)
            ->latest()
            ->take(5)
            ->get();

        /*
         * Valeurs par défaut pour le module Achats.
         *
         * Elles permettent au dashboard de fonctionner même lorsqu’un
         * utilisateur n’a pas encore d’entreprise associée.
         */
        $supplierInvoicesCount = 0;
        $monthlySupplierInvoicesCount = 0;
        $monthlyPurchasesTotal = 0;
        $supplierAmountPaid = 0;
        $supplierAmountRemaining = 0;
        $overdueSupplierInvoicesCount = 0;
        $unpaidSupplierInvoicesCount = 0;
        $partialSupplierInvoicesCount = 0;

        if ($company) {
            /*
             * Nombre total de factures fournisseurs reçues.
             */
            $supplierInvoicesCount = SupplierInvoice::where(
                'company_id',
                $company->id
            )->count();

            /*
             * Factures fournisseurs reçues pendant le mois en cours.
             */
            $monthlySupplierInvoicesCount = SupplierInvoice::where(
                'company_id',
                $company->id
            )
                ->whereMonth('received_at', now()->month)
                ->whereYear('received_at', now()->year)
                ->count();

            /*
             * Total TTC des achats reçus pendant le mois en cours.
             */
            $monthlyPurchasesTotal = SupplierInvoice::where(
                'company_id',
                $company->id
            )
                ->whereMonth('received_at', now()->month)
                ->whereYear('received_at', now()->year)
                ->sum('total_ttc');

            /*
             * Montant total déjà réglé sur les factures fournisseurs.
             */
            $supplierAmountPaid = SupplierInvoice::where(
                'company_id',
                $company->id
            )->sum('paid_amount');

            /*
             * Montant total restant à payer.
             *
             * Pour les anciennes factures dont remaining_amount est
             * encore null, on utilise leur total TTC.
             */
            $supplierAmountRemaining = SupplierInvoice::where(
                'company_id',
                $company->id
            )
                ->get()
                ->sum(function (SupplierInvoice $invoice) {
                    return (float) (
                        $invoice->remaining_amount
                        ?? $invoice->total_ttc
                    );
                });

            /*
             * Factures fournisseurs dont l’échéance est dépassée
             * et qui ne sont pas entièrement payées.
             */
            $overdueSupplierInvoicesCount = SupplierInvoice::where(
                'company_id',
                $company->id
            )
                ->where('payment_status', '!=', 'paid')
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now())
                ->count();

            /*
             * Factures fournisseurs encore entièrement à payer.
             */
            $unpaidSupplierInvoicesCount = SupplierInvoice::where(
                'company_id',
                $company->id
            )
                ->where('payment_status', 'unpaid')
                ->count();

            /*
             * Factures fournisseurs partiellement payées.
             */
            $partialSupplierInvoicesCount = SupplierInvoice::where(
                'company_id',
                $company->id
            )
                ->where('payment_status', 'partial')
                ->count();
        }

        return view(
            'dashboard',
            compact(
                'clientsCount',
                'quotesCount',
                'invoicesCount',
                'monthlyRevenue',
                'amountCollected',
                'amountToCollect',
                'overdueInvoicesCount',
                'unpaidInvoicesCount',
                'recentQuotes',
                'recentInvoices',

                /*
                 * Variables du module Achats.
                 */
                'supplierInvoicesCount',
                'monthlySupplierInvoicesCount',
                'monthlyPurchasesTotal',
                'supplierAmountPaid',
                'supplierAmountRemaining',
                'overdueSupplierInvoicesCount',
                'unpaidSupplierInvoicesCount',
                'partialSupplierInvoicesCount'
            )
        );
    }
}