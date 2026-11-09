<?php

namespace App\Services\SuperPdp;

use Illuminate\Validation\ValidationException;

/**
 * Contexte français explicite pour les seules factures ordinaires prises en charge.
 * Aucune lecture de profil, de PDF, de ligne ou de donnée fiscale ; aucune valeur par défaut.
 */
final readonly class FrenchInvoiceContext
{
    private function __construct(public string $businessProcessType, public array $notes) {}

    /** Contrôles communs aux diagnostics et à la préparation des factures persistées. */
    public static function storedBusinessProcessBlockers(mixed $stored, array $context): array
    {
        $blockers = [];
        if ($stored === null || (is_string($stored) && preg_match('/[^\s\p{Z}]/u', $stored) !== 1)) {
            $blockers[] = ['code' => 'invoice.business_process_type.missing', 'category' => 'missing_data',
                'field' => 'french_business_process_type', 'message' => 'Cadre de facturation non renseigné'];
        } elseif (! in_array($stored, ['B1', 'S1', 'M1'], true)) {
            $blockers[] = ['code' => 'invoice.business_process_type.invalid', 'category' => 'invalid_data',
                'field' => 'french_business_process_type', 'message' => 'Cadre de facturation enregistré invalide : B1, S1 ou M1 attendu.'];
        }
        if (array_key_exists('business_process_type', $context) && $context['business_process_type'] !== $stored) {
            $blockers[] = ['code' => 'invoice.business_process_type.context_conflict', 'category' => 'invalid_data',
                'field' => 'french_context.business_process_type',
                'message' => 'Le cadre fourni dans le contexte contredit le cadre enregistré sur la facture. Le contexte ne remplace pas la valeur persistée.'];
        }

        return $blockers;
    }

    /** Le contexte est complété exclusivement depuis l'instantané relu en base. */
    public static function fromInvoiceData(array $data, ?array $context): self
    {
        $context ??= [];
        $stored = $data['french_business_process_type'] ?? null;
        $errors = [];
        foreach (self::storedBusinessProcessBlockers($stored, $context) as $blocker) {
            $errors[$blocker['field']][] = $blocker['message'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $context['business_process_type'] = $stored;
        // Valider le stockage même si l'appelant fournit des mentions complètes.
        // Un contexte manuel ne peut ni combler ni remplacer les textes persistés.
        $storedContext = self::fromArray([
            'business_process_type' => $stored,
            'notes' => array_map(fn ($code) => [
                'subject_code' => $code, 'note' => $data['company_invoice_mentions'][$code] ?? null,
            ], ['PMT', 'PMD', 'AAB']),
        ]);
        foreach ($storedContext->notes as $note) {
            if (mb_strlen($note['note']) > 2000) {
                $errors['french_context.notes.'.$note['subject_code']][] = 'La mention enregistrée '.$note['subject_code'].' doit contenir au maximum 2 000 caractères.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if (! array_key_exists('notes', $context)) {
            $context['notes'] = $storedContext->notes;
        }

        $provided = self::fromArray($context);
        foreach ($storedContext->notes as $index => $note) {
            if ($provided->notes[$index]['note'] !== $note['note']) {
                $errors['french_context.notes.'.$note['subject_code']][] = isset($data['party_snapshot'])
                    ? 'La mention '.$note['subject_code'].' fournie dans le contexte contredit celle de l’instantané de la facture. Le contexte ne remplace pas l’instantané.'
                    : 'La mention '.$note['subject_code'].' fournie dans le contexte contredit celle enregistrée dans les paramètres de votre entreprise. Le contexte ne remplace pas le profil.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $storedContext;
    }

    /**
     * @param  array{business_process_type?: mixed, notes?: mixed}|null  $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null) {
            throw ValidationException::withMessages([
                'french_context' => 'Un contexte français explicite est obligatoire pour préparer le payload électronique.',
            ]);
        }

        $errors = [];
        foreach (array_diff(array_keys($data), ['business_process_type', 'notes']) as $key) {
            $errors['french_context.'.$key] = 'Cette propriété du contexte français n’est pas prise en charge.';
        }
        $mode = $data['business_process_type'] ?? null;
        if (! is_string($mode) || ! in_array($mode, ['B1', 'S1', 'M1'], true)) {
            $errors['french_context.business_process_type'] = 'Sélectionnez explicitement un cadre ordinaire B1, S1 ou M1.';
        }

        $notes = $data['notes'] ?? null;
        $byCode = [];
        if (! is_array($notes) || ! array_is_list($notes)) {
            $errors['french_context.notes'] = 'Fournissez une liste des trois mentions PMT, PMD et AAB.';
        } else {
            foreach ($notes as $index => $note) {
                $path = 'french_context.notes.'.$index;
                if (! is_array($note)) {
                    $errors[$path] = 'Chaque mention doit contenir subject_code et note.';

                    continue;
                }
                foreach (array_diff(array_keys($note), ['subject_code', 'note']) as $key) {
                    $errors[$path.'.'.$key] = 'Cette propriété de note n’est pas prise en charge.';
                }
                $code = $note['subject_code'] ?? null;
                if (! is_string($code) || ! in_array($code, ['PMT', 'PMD', 'AAB'], true)) {
                    $errors[$path.'.subject_code'] = 'Le code de mention doit être exactement PMT, PMD ou AAB.';
                } elseif (array_key_exists($code, $byCode)) {
                    $errors[$path.'.subject_code'] = 'Chaque mention PMT, PMD et AAB doit être fournie une seule fois.';
                } else {
                    // Conserver le texte original, y compris sa ponctuation et ses sauts de ligne.
                    $byCode[$code] = ['subject_code' => $code, 'note' => $note['note'] ?? null];
                }
                $text = $note['note'] ?? null;
                if (! is_string($text) || preg_match('/[^\s\p{Z}]/u', $text) !== 1) {
                    $errors[$path.'.note'] = 'Le texte de la mention est obligatoire et ne peut pas être blanc.';
                }
            }
            foreach (['PMT', 'PMD', 'AAB'] as $code) {
                if (! array_key_exists($code, $byCode)) {
                    $errors['french_context.notes.'.$code] = 'La mention '.$code.' doit être fournie explicitement.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self($mode, array_map(fn ($code) => $byCode[$code], ['PMT', 'PMD', 'AAB']));
    }
}
