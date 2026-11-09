<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Projection de lecture commune à tous les appelants de la vue PDF. */
class InvoicePdfPartyData
{
    public function __construct(private readonly InvoiceLogoArchive $logos) {}

    public function build(Invoice $invoice, ?object $legacyCompany = null): array
    {
        // Relire le JSON brut : le cast Eloquent confond notamment {} et [].
        $stored = $invoice->newQuery()->findOrFail($invoice->getKey());
        $json = $stored->getRawOriginal('party_snapshot');
        if ($json === null) {
            $stored->load('client', 'user.company');
            // FacturXService peut historiquement fournir User en l'absence de Company.
            $company = $legacyCompany ?? $stored->user->company;

            return [
                'from_snapshot' => false,
                'seller' => $company?->getAttributes() ?? [],
                'buyer' => array_merge($stored->client->getAttributes(), ['full_name' => $stored->client->full_name]),
                'seller_name' => $company?->company_name ?? $company?->name ?? $stored->user->name,
                'logo_path' => empty($company?->logo) ? null : public_path('storage/'.$company->logo),
            ];
        }

        try {
            $snapshot = InvoicePartySnapshot::fromJson($json)->toArray();
        } catch (InvoicePartySnapshotException $exception) {
            throw ValidationException::withMessages(['party_snapshot' => $exception->getMessage()]);
        }

        $seller = $snapshot['seller'];
        $buyer = $snapshot['buyer'];
        $buyer['full_name'] = $this->firstText([
            $buyer['full_name'] ?? null,
            trim(($buyer['first_name'] ?? '').' '.($buyer['last_name'] ?? '')),
            $buyer['company_name'] ?? null,
        ]);
        $seller['vat_number'] = $this->firstText([$seller['vat_number'] ?? null, $seller['tva_number'] ?? null]);

        return [
            'from_snapshot' => true,
            'seller' => $seller,
            'buyer' => $buyer,
            'seller_name' => $this->firstText([$seller['company_name'] ?? null, $seller['name'] ?? null, $seller['user_name'] ?? null]),
            'logo_path' => $snapshot['schema_version'] === InvoicePartySnapshot::PRIVATE_LOGO_SCHEMA_VERSION
                ? $this->logos->resolve((int) $stored->user_id, $seller['logo'] ?? null)
                : $this->historicalLogo($seller['logo'] ?? null),
        ];
    }

    private function firstText(array $values): string
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }

    private function historicalLogo(?string $logo): ?string
    {
        if ($logo === null || trim($logo) === '' || str_contains($logo, "\0")) {
            return null;
        }
        // Un logo capturé désigne uniquement un fichier local dans le stockage public.
        if (config('filesystems.disks.public.driver') !== 'local') {
            return null;
        }
        $disk = Storage::disk('public');
        $root = realpath($disk->path(''));
        $path = realpath($disk->path($logo));
        if ($root === false || $path === false || ! is_file($path) || ! is_readable($path)
            || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }
}
