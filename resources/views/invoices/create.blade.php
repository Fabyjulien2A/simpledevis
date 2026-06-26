<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Nouvelle facture
        </h2>
    </x-slot>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <form action="{{ route('invoices.store') }}" method="POST">
            @csrf

            @include('invoices._form')
        </form>
    </div>
</x-app-layout>