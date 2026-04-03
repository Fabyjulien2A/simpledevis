<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Modifier le client
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="rounded-xl bg-white p-6 shadow">
                <form action="{{ route('clients.update', $client) }}" method="POST">
                    @csrf
                    @method('PUT')

                    @include('clients._form', [
                        'buttonText' => 'Mettre à jour le client'
                    ])
                </form>
            </div>
        </div>
    </div>
</x-app-layout>