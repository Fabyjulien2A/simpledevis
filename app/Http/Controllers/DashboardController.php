<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
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

        $monthlyRevenue = Invoice::where('user_id', $userId)
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->sum('total_ttc');

        $amountCollected = Payment::whereHas('invoice', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })->sum('amount');

        $amountToCollect = Invoice::where('user_id', $userId)
            ->get()
            ->sum(function ($invoice) {
                return $invoice->remaining_amount;
            });

        $overdueInvoicesCount = Invoice::where('user_id', $userId)
            ->where('status', '!=', 'payee')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->count();

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
            'monthlyRevenue',
            'amountCollected',
            'amountToCollect',
            'overdueInvoicesCount',
            'unpaidInvoicesCount',
            'recentQuotes',
            'recentInvoices'
        ));
    }
}