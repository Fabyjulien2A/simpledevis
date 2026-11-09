<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\SuperPdp\FrenchDirectoryConsultationException;
use App\Services\SuperPdp\FrenchDirectoryConsultationFactory;
use App\Services\SuperPdp\FrenchDirectoryEntriesAnalysis;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

final class ClientDirectoryController extends Controller
{
    public function __invoke(
        Request $request,
        Client $client,
        FrenchDirectoryConsultationFactory $factory,
        FrenchDirectoryEntriesAnalysis $analysis,
    ): Response {
        abort_unless($client->user_id === $request->user()->id, 403);

        if ($client->client_type !== 'professional' || $client->country_code !== 'FR') {
            return $this->display($client, error: 'La consultation est réservée aux clients professionnels français.', status: 422);
        }
        if (! is_string($client->siret) || ! preg_match('/\A[0-9]{14}\z/', $client->siret)) {
            return $this->display($client, error: 'Renseignez et enregistrez un SIRET de 14 chiffres sur la fiche client avant de consulter l’annuaire.', status: 422);
        }

        $siren = substr($client->siret, 0, 9);
        try {
            $result = $factory->create()->listEntries($siren);
            $directoryAnalysis = $analysis->analyze($siren, $result['data'], array_diff_key($result, ['data' => true]));

            return $this->display($client, $directoryAnalysis, siren: $siren);
        } catch (FrenchDirectoryConsultationException $exception) {
            return $this->display($client, error: $exception->getMessage(),
                status: $exception->reason === 'unavailable' ? 503 : 502);
        } catch (InvalidArgumentException) {
            return $this->display($client, error: 'La consultation de l’annuaire est indisponible : sa configuration doit être vérifiée.', status: 503);
        }
    }

    private function display(Client $client, ?array $directoryAnalysis = null, ?string $error = null, int $status = 200, ?string $siren = null): Response
    {
        // Résultat limité à cette réponse : aucune session, cache ou écriture métier.
        return response()->view('clients.show', [
            'client' => $client, 'directoryAnalysis' => $directoryAnalysis,
            'directoryError' => $error, 'directorySiren' => $siren,
        ], $status)->header('Cache-Control', 'private, no-store');
    }
}
