<?php
declare(strict_types=1);

namespace App\RPT;

/**
 * Immutable DTO representing a property assessment record from an external RPT
 * (Real Property Tax) assessment system (Phase 9 — TASK-077b, architecture.md §11).
 *
 * Keeps core GIS and parcel logic decoupled from external assessment databases.
 */
class PropertyAssessmentRecord
{
    public function __construct(
        public readonly string $taxDeclarationNo,
        public readonly string $propertyIndexNumber,
        public readonly ?string $parcelCode,
        public readonly string $ownerName,
        public readonly float $marketValue,
        public readonly float $assessedValue,
        public readonly string $classification,
        public readonly ?string $psgcCode,
        public readonly int $effectiveYear,
        public readonly string $status = 'ACTIVE'
    ) {
    }

    public function toArray(): array
    {
        return [
            'tax_declaration_no'    => $this->taxDeclarationNo,
            'property_index_number' => $this->propertyIndexNumber,
            'parcel_code'           => $this->parcelCode,
            'owner_name'            => $this->ownerName,
            'market_value'          => $this->marketValue,
            'assessed_value'        => $this->assessedValue,
            'classification'        => $this->classification,
            'psgc_code'             => $this->psgcCode,
            'effective_year'        => $this->effectiveYear,
            'status'                => $this->status,
        ];
    }
}
