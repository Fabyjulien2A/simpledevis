<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\CompanySettingController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SubscriptionController;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/billing/portal', [App\Http\Controllers\BillingController::class, 'portal'])
    ->middleware(['auth'])
    ->name('billing.portal');
Route::get('/billing', function () {
    return view('billing.index');
})->middleware(['auth'])->name('billing.index');


Route::middleware(['auth'])->group(function () {
    Route::get('/billing', [SubscriptionController::class, 'index'])->name('billing.index');
    Route::post('/subscribe/{plan}', [SubscriptionController::class, 'subscribe'])->name('billing.subscribe');
    Route::get('/subscribe/success', [SubscriptionController::class, 'success'])->name('billing.success');
    Route::get('/subscribe/cancel', [SubscriptionController::class, 'cancel'])->name('billing.cancel');
});

Route::post('/invoices/{invoice}/action', [InvoiceController::class, 'handleAction'])
    ->name('invoices.action');

    Route::get('/tarifs', function () {
    return view('pricing');
})->name('pricing');

Route::post('/quotes/{quote}/duplicate', [QuoteController::class, 'duplicate'])
    ->name('quotes.duplicate');

    Route::post('/invoices/{invoice}/duplicate', [InvoiceController::class, 'duplicate'])
    ->name('invoices.duplicate');

Route::post('/invoices/{invoice}/mark-as-paid', [InvoiceController::class, 'markAsPaid'])
    ->name('invoices.markAsPaid');

Route::post('/invoices/{invoice}/mark-as-unpaid', [InvoiceController::class, 'markAsUnpaid'])
    ->name('invoices.markAsUnpaid');

Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])
    ->name('invoices.pdf');

    Route::post('/invoices/{invoice}/add-payment', [InvoiceController::class, 'addPayment'])
    ->name('invoices.addPayment');

Route::post('/quotes/{quote}/convert-to-invoice', [QuoteController::class, 'convertToInvoice'])
    ->name('quotes.convertToInvoice');

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    
Route::middleware('auth')->group(function () {
    Route::get('/company', [CompanySettingController::class, 'edit'])->name('company.edit');
    Route::post('/company', [CompanySettingController::class, 'update'])->name('company.update');
});



Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::post('/invoices/{invoice}/send-email', [InvoiceController::class, 'sendByEmail'])
    ->name('invoices.sendEmail');

Route::middleware('auth')->group(function () {
    // Profil utilisateur
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::resource('quotes', QuoteController::class);
    // CRUD clients
    Route::resource('clients', ClientController::class);
});

Route::get('/quotes/{quote}/pdf', [QuoteController::class, 'pdf'])->name('quotes.pdf');

require __DIR__.'/auth.php';