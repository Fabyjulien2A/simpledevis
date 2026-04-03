<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $userId = auth()->id();

        $clientsCount = Client::where('user_id', $userId)->count();
        $quotesCount = Quote::where('user_id', $userId)->count();
        $invoicesCount = Invoice::where('user_id', $userId)->count();

        $amountCollected = Invoice::where('user_id', $userId)->sum('amount_paid');
        $amountToCollect = Invoice::where('user_id', $userId)
            ->get()
            ->sum(function ($invoice) {
                return (float) $invoice->total_ttc - (float) $invoice->amount_paid;
            });

        $unpaidInvoicesCount = Invoice::where('user_id', $userId)
            ->whereIn('status', ['non_payee', 'partiellement_payee'])
            ->count();

        $recentQuotes = Quote::where('user_id', $userId)
            ->latest()
            ->take(5)
            ->get();

        $recentInvoices = Invoice::where('user_id', $userId)
            ->latest()
            ->take(5)
            ->get();

        return view('dashboard', compact(
            'clientsCount',
            'quotesCount',
            'invoicesCount',
            'amountCollected',
            'amountToCollect',
            'unpaidInvoicesCount',
            'recentQuotes',
            'recentInvoices'
        ));
    }
}