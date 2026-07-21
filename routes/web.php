<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CompanySettingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SupplierInvoiceController;
use App\Http\Controllers\SuperPdpController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes publiques
|--------------------------------------------------------------------------
*/

Route::view('/', 'welcome')->name('home');

Route::view('/tarifs', 'pricing')
    ->name('pricing');

Route::view('/reforme-facturation', 'reforme')
    ->name('reforme');

Route::view('/contact', 'contact')
    ->name('contact');

Route::view('/mentions-legales', 'legal.mentions')
    ->name('legal.mentions');

Route::view('/confidentialite', 'legal.confidentialite')
    ->name('legal.confidentialite');

Route::view('/cgv', 'legal.cgv')
    ->name('legal.cgv');

/*
|--------------------------------------------------------------------------
| Authentification Laravel Breeze
|--------------------------------------------------------------------------
*/

require __DIR__.'/auth.php';

/*
|--------------------------------------------------------------------------
| Routes protégées
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Tableau de bord
    |--------------------------------------------------------------------------
    */

    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware('verified')
        ->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | Profil utilisateur
    |--------------------------------------------------------------------------
    */

    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');

    /*
    |--------------------------------------------------------------------------
    | Clients
    |--------------------------------------------------------------------------
    */

    Route::resource('clients', ClientController::class);

    /*
    |--------------------------------------------------------------------------
    | Devis
    |--------------------------------------------------------------------------
    */

    Route::resource('quotes', QuoteController::class);

    Route::post(
        '/quotes/{quote}/duplicate',
        [QuoteController::class, 'duplicate']
    )->name('quotes.duplicate');

    Route::post(
        '/quotes/{quote}/convert-to-invoice',
        [QuoteController::class, 'convertToInvoice']
    )->name('quotes.convertToInvoice');

    Route::get(
        '/quotes/{quote}/pdf',
        [QuoteController::class, 'pdf']
    )->name('quotes.pdf');

    /*
    |--------------------------------------------------------------------------
    | Factures clients
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/invoices',
        [InvoiceController::class, 'index']
    )->name('invoices.index');

    Route::get(
        '/invoices/create',
        [InvoiceController::class, 'create']
    )->name('invoices.create');

    Route::post(
        '/invoices',
        [InvoiceController::class, 'store']
    )->name('invoices.store');

    Route::get(
        '/invoices/{invoice}',
        [InvoiceController::class, 'show']
    )->name('invoices.show');

    Route::get(
        '/invoices/{invoice}/edit',
        [InvoiceController::class, 'edit']
    )->name('invoices.edit');

    Route::put(
        '/invoices/{invoice}',
        [InvoiceController::class, 'update']
    )->name('invoices.update');

    Route::delete(
        '/invoices/{invoice}',
        [InvoiceController::class, 'destroy']
    )->name('invoices.destroy');

    Route::get(
        '/invoices/{invoice}/pdf',
        [InvoiceController::class, 'pdf']
    )->name('invoices.pdf');

    Route::get(
        '/invoices/{invoice}/xml',
        [InvoiceController::class, 'xml']
    )->name('invoices.xml');

    Route::post(
        '/invoices/{invoice}/send-email',
        [InvoiceController::class, 'sendByEmail']
    )->name('invoices.sendEmail');

    Route::post(
        '/invoices/{invoice}/action',
        [InvoiceController::class, 'handleAction']
    )->name('invoices.action');

    Route::post(
        '/invoices/{invoice}/duplicate',
        [InvoiceController::class, 'duplicate']
    )->name('invoices.duplicate');

    Route::post(
        '/invoices/{invoice}/mark-as-paid',
        [InvoiceController::class, 'markAsPaid']
    )->name('invoices.markAsPaid');

    Route::post(
        '/invoices/{invoice}/mark-as-unpaid',
        [InvoiceController::class, 'markAsUnpaid']
    )->name('invoices.markAsUnpaid');

    Route::post(
        '/invoices/{invoice}/add-payment',
        [InvoiceController::class, 'addPayment']
    )->name('invoices.addPayment');

    /*
    |--------------------------------------------------------------------------
    | Facturation électronique / SUPER PDP
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/facturation-electronique',
        [SuperPdpController::class, 'index']
    )->name('electronic-invoicing.index');

    Route::get(
        '/facturation-electronique/connect',
        [SuperPdpController::class, 'connect']
    )->name('electronic-invoicing.connect');

    Route::get(
        '/facturation-electronique/callback',
        [SuperPdpController::class, 'callback']
    )->name('electronic-invoicing.callback');

    Route::post(
        '/facturation-electronique/synchroniser',
        [SuperPdpController::class, 'syncInvoices']
    )->name('electronic-invoicing.sync');

    Route::post(
        '/facturation-electronique/disconnect',
        [SuperPdpController::class, 'disconnect']
    )->name('electronic-invoicing.disconnect');

    /*
    |--------------------------------------------------------------------------
    | Factures fournisseurs reçues
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/factures-recues',
        [SupplierInvoiceController::class, 'index']
    )->name('supplier-invoices.index');

    Route::get(
        '/factures-recues/{supplierInvoice}',
        [SupplierInvoiceController::class, 'show']
    )->name('supplier-invoices.show');

    Route::get(
    '/factures-recues/{supplierInvoice}/xml',
    [SupplierInvoiceController::class, 'downloadXml']
)->name('supplier-invoices.xml');

Route::get(
    '/factures-recues/{supplierInvoice}/json',
    [SupplierInvoiceController::class, 'downloadJson']
)->name('supplier-invoices.json');

Route::get(
    '/supplier-invoices/{supplierInvoice}/pdf',
    [SupplierInvoiceController::class, 'downloadPdf']
)->name('supplier-invoices.pdf');

Route::post(
    '/factures-recues/{supplierInvoice}/paiements',
    [SupplierInvoiceController::class, 'storePayment']
)->name('supplier-invoices.payments.store');

    /*
    |--------------------------------------------------------------------------
    | Paramètres de l'entreprise
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/company',
        [CompanySettingController::class, 'edit']
    )->name('company.edit');

    Route::post(
        '/company',
        [CompanySettingController::class, 'update']
    )->name('company.update');

    /*
    |--------------------------------------------------------------------------
    | Abonnement et facturation
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/billing',
        [SubscriptionController::class, 'index']
    )->name('billing.index');

    Route::post(
        '/subscribe/{plan}',
        [SubscriptionController::class, 'subscribe']
    )->name('billing.subscribe');

    Route::get(
        '/subscribe/success',
        [SubscriptionController::class, 'success']
    )->name('billing.success');

    Route::get(
        '/subscribe/cancel',
        [SubscriptionController::class, 'cancel']
    )->name('billing.cancel');

    Route::post(
        '/billing/portal',
        [BillingController::class, 'portal']
    )->name('billing.portal');
});