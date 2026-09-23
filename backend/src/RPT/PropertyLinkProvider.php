<?php
declare(strict_types=1);

namespace App\RPT;

/**
 * Outbound port interface for external Property Assessment (RPT) integration
 * (TASK-077b, architecture.md §11).
 *
 * Provides lookups using stable external keys (tax_declaration_no, parcel_code,
 * PSGC) without requiring foreign-key coupling to third-party assessment systems.
 */
interface PropertyLinkProvider
{
    /**
     * Lookup an external RPT assessment record by Tax Declaration Number.
     */
    public function lookupByTaxDeclaration(string $tdNumber): ?PropertyAssessmentRecord;

    /**
     * Lookup an external RPT assessment record by standard parcel code.
     */
    public function lookupByParcelCode(string $parcelCode): ?PropertyAssessmentRecord;

    /**
     * Lookup assessment records within a PSGC barangay or municipality.
     *
     * @return PropertyAssessmentRecord[]
     */
    public function lookupByPsgc(string $psgcCode): array;
}
