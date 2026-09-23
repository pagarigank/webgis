<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\RPT\PropertyAssessmentRecord;
use App\RPT\PropertyLinkProvider;
use App\RPT\StubPropertyLinkProvider;
use PHPUnit\Framework\TestCase;

/**
 * TASK-077b — RPT outbound adapter port and stub implementation.
 *
 * Verifies that external property assessment records can be looked up by TD,
 * parcel code, or PSGC without database coupling.
 */
class PropertyLinkAdapterTest extends TestCase
{
    private PropertyLinkProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new StubPropertyLinkProvider();
    }

    public function testImplementsPropertyLinkProviderInterface(): void
    {
        $this->assertInstanceOf(PropertyLinkProvider::class, $this->provider);
    }

    public function testLookupByTaxDeclarationReturnsRecord(): void
    {
        $record = $this->provider->lookupByTaxDeclaration('TD-2024-00123');

        $this->assertNotNull($record);
        $this->assertSame('TD-2024-00123', $record->taxDeclarationNo);
        $this->assertSame('112-02-001-04-001', $record->propertyIndexNumber);
        $this->assertSame('Juan Dela Cruz', $record->ownerName);
        $this->assertSame(1500000.00, $record->marketValue);
        $this->assertSame(300000.00, $record->assessedValue);
        $this->assertSame('RESIDENTIAL', $record->classification);
        $this->assertSame(2024, $record->effectiveYear);
        $this->assertSame('ACTIVE', $record->status);
    }

    public function testLookupByTaxDeclarationIsCaseInsensitive(): void
    {
        $record = $this->provider->lookupByTaxDeclaration('td-2024-00123');
        $this->assertNotNull($record);
        $this->assertSame('Juan Dela Cruz', $record->ownerName);
    }

    public function testLookupByTaxDeclarationNotFoundReturnsNull(): void
    {
        $record = $this->provider->lookupByTaxDeclaration('NON-EXISTENT-TD');
        $this->assertNull($record);
    }

    public function testLookupByParcelCodeReturnsRecord(): void
    {
        $record = $this->provider->lookupByParcelCode('PRC-001-LOT11');

        $this->assertNotNull($record);
        $this->assertSame('TD-2024-00456', $record->taxDeclarationNo);
        $this->assertSame('Maria Santos', $record->ownerName);
        $this->assertSame('COMMERCIAL', $record->classification);
    }

    public function testLookupByPsgcReturnsMatchingRecords(): void
    {
        $records = $this->provider->lookupByPsgc('043404001');

        $this->assertCount(2, $records);
        $tdNumbers = array_map(fn ($r) => $r->taxDeclarationNo, $records);
        $this->assertContains('TD-2024-00123', $tdNumbers);
        $this->assertContains('TD-2024-00456', $tdNumbers);
    }

    public function testRegisterRecordAllowsDynamicExtension(): void
    {
        $stub = new StubPropertyLinkProvider();
        $custom = new PropertyAssessmentRecord(
            taxDeclarationNo: 'TD-CUSTOM-999',
            propertyIndexNumber: 'PIN-CUSTOM-999',
            parcelCode: 'PRC-CUSTOM-999',
            ownerName: 'Test Owner',
            marketValue: 800000.0,
            assessedValue: 160000.0,
            classification: 'RESIDENTIAL',
            psgcCode: '043404999',
            effectiveYear: 2025
        );

        $stub->registerRecord($custom);
        $retrieved = $stub->lookupByTaxDeclaration('TD-CUSTOM-999');

        $this->assertNotNull($retrieved);
        $this->assertSame('Test Owner', $retrieved->ownerName);
        $this->assertSame('PRC-CUSTOM-999', $retrieved->parcelCode);
    }
}
