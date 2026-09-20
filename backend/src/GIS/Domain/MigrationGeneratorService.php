<?php
declare(strict_types=1);

namespace App\GIS\Domain;

class MigrationGeneratorService
{
    private string $migrationsPath;

    public function __construct(string $migrationsPath)
    {
        $this->migrationsPath = rtrim($migrationsPath, '/\\') . '/';
    }

    /**
     * Generate a migration to create or drop an expression index for a GIS layer field.
     *
     * @param int $layerId
     * @param string $fieldName
     * @param bool $isSearchableOrSortable Whether to add (true) or drop (false) the index.
     * @return string The path to the generated migration file.
     */
    public function generateExpressionIndexMigration(int $layerId, string $fieldName, bool $isSearchableOrSortable): string
    {
        $action = $isSearchableOrSortable ? 'add' : 'drop';
        $timestamp = date('YmdHis');
        // Phinx class names must be CamelCase.
        $cleanFieldName = preg_replace('/[^a-zA-Z0-9_]/', '', $fieldName);
        $camelCaseField = str_replace('_', '', ucwords($cleanFieldName, '_'));
        $className = ucfirst($action) . 'IdxLayer' . $layerId . $camelCaseField . $timestamp;
        
        $filename = "{$timestamp}_{$action}_idx_layer_{$layerId}_{$cleanFieldName}.php";
        $filepath = $this->migrationsPath . $filename;
        
        $indexName = "idx_features_l{$layerId}_{$cleanFieldName}";

        if ($isSearchableOrSortable) {
            $up = "\$this->execute(\"CREATE INDEX IF NOT EXISTS {$indexName} ON app.gis_features ((attributes->>'{$fieldName}')) WHERE layer_id = {$layerId}\");";
            $down = "\$this->execute(\"DROP INDEX IF EXISTS {$indexName}\");";
        } else {
            $up = "\$this->execute(\"DROP INDEX IF EXISTS {$indexName}\");";
            $down = "\$this->execute(\"CREATE INDEX IF NOT EXISTS {$indexName} ON app.gis_features ((attributes->>'{$fieldName}')) WHERE layer_id = {$layerId}\");";
        }

        $template = <<<PHP
<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class {$className} extends AbstractMigration
{
    public function up(): void
    {
        {$up}
    }

    public function down(): void
    {
        {$down}
    }
}
PHP;

        if (!is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0777, true);
        }

        file_put_contents($filepath, $template);
        
        return $filepath;
    }
}
