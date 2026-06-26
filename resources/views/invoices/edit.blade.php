<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Modifier la facture {{ $invoice->invoice_number }}
        </h2>
    </x-slot>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <form action="{{ route('invoices.update', $invoice) }}" method="POST">
            @csrf
            @method('PUT')

            @include('invoices._form')
        </form>
    </div>
</x-app-layout>