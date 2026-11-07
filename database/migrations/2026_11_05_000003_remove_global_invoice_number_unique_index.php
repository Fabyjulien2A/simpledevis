<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GLOBAL_INDEX = 'invoices_invoice_number_unique';

    private const USER_INDEX = 'invoices_user_number_unique';

    public function up(): void
    {
        $indexes = array_column(Schema::getIndexes('invoices'), null, 'name');
        $this->requireUniqueIndex($indexes, self::USER_INDEX, ['user_id', 'invoice_number']);
        $this->requireUniqueIndex($indexes, self::GLOBAL_INDEX, ['invoice_number']);

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(self::GLOBAL_INDEX);
        });
    }

    public function down(): void
    {
        $indexes = array_column(Schema::getIndexes('invoices'), null, 'name');
        $this->requireUniqueIndex($indexes, self::USER_INDEX, ['user_id', 'invoice_number']);

        if (isset($indexes[self::GLOBAL_INDEX])) {
            throw new RuntimeException('Schéma invoices inattendu : l’index global existe déjà. Aucune modification effectuée.');
        }

        // Réversibilité conditionnelle : ne jamais renuméroter ni supprimer des factures.
        // Suspendre les écritures pendant cette opération de schéma. Si un doublon
        // apparaît après ce contrôle, la création UNIQUE doit elle-même échouer.
        $duplicatesExist = DB::table('invoices')
            ->whereNotNull('invoice_number')
            ->groupBy('invoice_number')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicatesExist) {
            throw new RuntimeException('Rollback refusé : plusieurs factures partagent un numéro non nul. L’unicité globale ne peut pas être restaurée sans modifier les documents. Aucune donnée modifiée.');
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique('invoice_number', self::GLOBAL_INDEX);
        });
    }

    private function requireUniqueIndex(array $indexes, string $name, array $columns): void
    {
        $index = $indexes[$name] ?? null;

        if ($index === null || ! $index['unique'] || $index['primary'] || $index['columns'] !== $columns) {
            throw new RuntimeException('Schéma invoices inattendu : l’index ' . $name
                . ' doit être UNIQUE sur (' . implode(', ', $columns) . '). Aucune modification effectuée.');
        }
    }
};
