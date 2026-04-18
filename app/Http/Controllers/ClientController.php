<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Contrôleur de gestion des clients.
 *
 * Permet à l'utilisateur connecté de :
 * - voir la liste de ses clients
 * - créer un client
 * - modifier un client
 * - supprimer un client
 */
class ClientController extends Controller
{
    /**
     * Affiche la liste des clients de l'utilisateur connecté.
     */
    public function index(): View
    {
        $clients = Client::where('user_id', auth()->id())
            ->latest()
            ->paginate(10);

        return view('clients.index', compact('clients'));
    }

    /**
     * Affiche le formulaire de création d'un client.
     */
    public function create(): View
    {
        return view('clients.create');
    }

    /**
     * Enregistre un nouveau client en base de données.
     */
    public function store(Request $request): RedirectResponse
    {

        $user = auth()->user();

        // Limite plan gratuit
        if (!$user->isSubscribed()) {
            $clientsCount = $user->clients()->count();

            if ($clientsCount >= 5) {
                return redirect()
                    ->back()
                    ->with('error', 'Tu as atteint la limite de 5 clients. Passe à une offre supérieure.');
            }
        }

        // Validation des données du formulaire
        $validated = $request->validate([
            'company_name' => ['nullable', 'string', 'max:255'],
            'first_name'   => ['nullable', 'string', 'max:255'],
            'last_name'    => ['nullable', 'string', 'max:255'],
            'email'        => ['nullable', 'email', 'max:255'],
            'phone'        => ['nullable', 'string', 'max:30'],
            'address'      => ['nullable', 'string', 'max:255'],
            'postal_code'  => ['nullable', 'string', 'max:20'],
            'city'         => ['nullable', 'string', 'max:255'],
            'notes'        => ['nullable', 'string'],
        ]);

        // On associe automatiquement le client à l'utilisateur connecté
        $validated['user_id'] = auth()->id();

        Client::create($validated);

        return redirect()
            ->route('clients.index')
            ->with('success', 'Client ajouté avec succès.');
    }

    /**
     * Affiche le détail d'un client.
     */
    public function show(Client $client): View
    {
        // Sécurisation : on vérifie que le client appartient bien à l'utilisateur connecté
        $this->authorizeClient($client);

        return view('clients.show', compact('client'));
    }

    /**
     * Affiche le formulaire d'édition d'un client.
     */
    public function edit(Client $client): View
    {
        $this->authorizeClient($client);

        return view('clients.edit', compact('client'));
    }

    /**
     * Met à jour un client existant.
     */
    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeClient($client);

        $validated = $request->validate([
            'company_name' => ['nullable', 'string', 'max:255'],
            'first_name'   => ['nullable', 'string', 'max:255'],
            'last_name'    => ['nullable', 'string', 'max:255'],
            'email'        => ['nullable', 'email', 'max:255'],
            'phone'        => ['nullable', 'string', 'max:30'],
            'address'      => ['nullable', 'string', 'max:255'],
            'postal_code'  => ['nullable', 'string', 'max:20'],
            'city'         => ['nullable', 'string', 'max:255'],
            'notes'        => ['nullable', 'string'],
        ]);

        $client->update($validated);

        return redirect()
            ->route('clients.index')
            ->with('success', 'Client mis à jour avec succès.');
    }

    /**
     * Supprime un client.
     */
    public function destroy(Client $client): RedirectResponse
    {
        $this->authorizeClient($client);

        $client->delete();

        return redirect()
            ->route('clients.index')
            ->with('success', 'Client supprimé avec succès.');
    }

    /**
     * Vérifie qu'un client appartient bien à l'utilisateur connecté.
     *
     * Si ce n'est pas le cas, on renvoie une erreur 403.
     */
    private function authorizeClient(Client $client): void
    {
        abort_if($client->user_id !== auth()->id(), 403);
    }
}
