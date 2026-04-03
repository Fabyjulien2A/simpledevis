<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Ajouter un client
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="rounded-xl bg-white p-6 shadow">
                <form action="{{ route('clients.store') }}" method="POST">
                    @csrf

                    @include('clients._form', [
                        'buttonText' => 'Créer le client'
                    ])
                </form>
            </div>
        </div>
    </div>
</x-app-layout>