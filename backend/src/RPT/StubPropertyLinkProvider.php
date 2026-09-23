<?php
declare(strict_types=1);

namespace App\RPT;

/**
 * In-memory stub adapter implementing PropertyLinkProvider (TASK-077b).
 *
 * Simulates external assessment database responses using deterministic fixtures.
 * Operates without any database connection or external service dependency.
 */
class StubPropertyLinkProvider implements PropertyLinkProvider
{
    /** @var array<string, PropertyAssessmentRecord> */
    private array $recordsByTd = [];

    /** @var array<string, PropertyAssessmentRecord> */
    private array $recordsByParcelCode = [];

    /** @var array<string, PropertyAssessmentRecord[]> */
    private array $recordsByPsgc = [];

    public function __construct()
    {
        $this->seedStubRecords();
    }

    public function lookupByTaxDeclaration(string $tdNumber): ?PropertyAssessmentRecord
    {
        $key = strtoupper(trim($tdNumber));
        return $this->recordsByTd[$key] ?? null;
    }

    public function lookupByParcelCode(string $parcelCode): ?PropertyAssessmentRecord
    {
        $key = strtoupper(trim($parcelCode));
        return $this->recordsByParcelCode[$key] ?? null;
    }

    public function lookupByPsgc(string $psgcCode): array
    {
        $key = trim($psgcCode);
        return $this->recordsByPsgc[$key] ?? [];
    }

    /**
     * Add a record dynamically for testing scenarios.
     */
    public function registerRecord(PropertyAssessmentRecord $record): void
    {
        $this->recordsByTd[strtoupper($record->taxDeclarationNo)] = $record;
        if ($record->parcelCode !== null) {
            $this->recordsByParcelCode[strtoupper($record->parcelCode)] = $record;
        }
        if ($record->psgcCode !== null) {
            $this->recordsByPsgc[$record->psgcCode][] = $record;
        }
    }

    private function seedStubRecords(): void
    {
        $fixtures = [
            new PropertyAssessmentRecord(
                taxDeclarationNo: 'TD-2024-00123',
                propertyIndexNumber: '112-02-001-04-001',
                parcelCode: 'PRC-001-LOT10',
                ownerName: 'Juan Dela Cruz',
                marketValue: 1500000.00,
                assessedValue: 300000.00,
                classification: 'RESIDENTIAL',
                psgcCode: '043404001',
                effectiveYear: 2024,
                status: 'ACTIVE'
            ),
            new PropertyAssessmentRecord(
                taxDeclarationNo: 'TD-2024-00456',
                propertyIndexNumber: '112-02-001-04-002',
                parcelCode: 'PRC-001-LOT11',
                ownerName: 'Maria Santos',
                marketValue: 2800000.00,
                assessedValue: 1400000.00,
                classification: 'COMMERCIAL',
                psgcCode: '043404001',
                effectiveYear: 2024,
                status: 'ACTIVE'
            ),
            new PropertyAssessmentRecord(
                taxDeclarationNo: 'TD-2023-00999',
                propertyIndexNumber: '112-02-005-02-015',
                parcelCode: null,
                ownerName: 'AgriCorp Philippines Inc.',
                marketValue: 5000000.00,
                assessedValue: 1000000.00,
                classification: 'AGRICULTURAL',
                psgcCode: '043404005',
                effectiveYear: 2023,
                status: 'ACTIVE'
            ),
        ];

        foreach ($fixtures as $fix) {
            $this->registerRecord($fix);
        }
    }
}
