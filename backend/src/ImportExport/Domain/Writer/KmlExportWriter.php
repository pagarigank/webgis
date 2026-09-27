<?php
declare(strict_types=1);

namespace App\ImportExport\Domain\Writer;

use App\ImportExport\Domain\ExportDataset;

/**
 * TASK-127 — KML writer.
 *
 * KML is fixed to WGS 84 by the OGC spec, so this writer reports no selectable
 * CRS (the service turns that into a 422 before we get here).
 *
 * The provenance block goes in the Document description, which is where Google
 * Earth and ArcGIS surface it, and each Placemark repeats the feature's own
 * attributes in its description so a placemark is self-describing once detached
 * from the document.
 */
final class KmlExportWriter implements ExportWriter
{
    public function format(): string
    {
        return 'KML';
    }

    public function allowedCrs(): ?string
    {
        return null;
    }

    public function write(ExportDataset $dataset): array
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $kml = $doc->createElement('kml');
        $kml->setAttribute('xmlns', 'http://www.opengis.net/kml/2.2');
        $doc->appendChild($kml);

        $document = $doc->createElement('Document');
        $kml->appendChild($document);

        $document->appendChild($this->text($doc, 'name', sprintf('webgis export - layer %d', $dataset->layerId)));
        $document->appendChild($this->cdata($doc, 'description', $dataset->provenance->toText()));
        $document->appendChild($this->style($doc));

        foreach ($dataset->features as $index => $row) {
            $geometry = $this->kmlGeometry($doc, $row['geometry'] ?? null);
            if ($geometry === null) {
                // A feature with no geometry cannot become a Placemark. Skipping
                // it is honest; emitting a Placemark with an empty geometry would
                // show up as a broken pin in the viewer.
                continue;
            }

            $placemark = $doc->createElement('Placemark');
            $placemark->appendChild($this->text($doc, 'name', (string) (
                $row['attributes']['label'] ?? $row['id'] ?? ('feature ' . ($index + 1))
            )));
            $placemark->appendChild($this->cdata($doc, 'description', $this->describe($row)));
            $placemark->appendChild($geometry);
            $document->appendChild($placemark);
        }

        return [
            'content_type' => 'application/vnd.google-earth.kml+xml',
            'body'         => (string) $doc->saveXML(),
        ];
    }

    // ------------------------------------------------------------------
    // DOM helpers: createElement() takes a string, so text is appended.
    // ------------------------------------------------------------------

    private function text(\DOMDocument $doc, string $tag, string $value): \DOMElement
    {
        $node = $doc->createElement($tag);
        $node->appendChild($doc->createTextNode($value));
        return $node;
    }

    private function cdata(\DOMDocument $doc, string $tag, string $value): \DOMElement
    {
        $node = $doc->createElement($tag);
        $node->appendChild($doc->createCDATASection($value));
        return $node;
    }

    private function style(\DOMDocument $doc): \DOMElement
    {
        $style = $doc->createElement('Style');
        $style->setAttribute('id', 'webgis-normal');

        $line = $doc->createElement('LineStyle');
        $line->appendChild($this->text($doc, 'color', 'ff0000ff'));
        $line->appendChild($this->text($doc, 'width', '2'));

        $poly = $doc->createElement('PolyStyle');
        $poly->appendChild($this->text($doc, 'color', 'ff0000ff'));

        $style->appendChild($line);
        $style->appendChild($poly);
        return $style;
    }

    // ------------------------------------------------------------------
    // Geometry
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $geometry decoded GeoJSON geometry
     */
    private function kmlGeometry(\DOMDocument $doc, ?array $geometry): ?\DOMElement
    {
        if ($geometry === null || !isset($geometry['type'])) {
            return null;
        }

        $coordinates = $geometry['coordinates'] ?? null;

        return match ((string) $geometry['type']) {
            'Point'          => $this->flat($doc, 'Point', $coordinates),
            'LineString'     => $this->flat($doc, 'LineString', $coordinates),
            'MultiLineString' => $this->multi($doc, 'MultiGeometry', $coordinates, false),
            'Polygon'        => $this->polygon($doc, $coordinates),
            'MultiPolygon'   => $this->multi($doc, 'MultiGeometry', $coordinates, true),
            default          => null,
        };
    }

    /**
     * A single ring of positions: KML wants "lon,lat lon,lat".
     */
    private function flat(\DOMDocument $doc, string $tag, mixed $positions): \DOMElement
    {
        return $this->text($doc, $tag, self::positions($positions));
    }

    /**
     * @param mixed $rings array of rings, each an array of positions
     */
    private function polygon(\DOMDocument $doc, mixed $rings): \DOMElement
    {
        $polygon = $doc->createElement('Polygon');
        if (!is_array($rings)) {
            return $polygon;
        }

        $outer = true;
        foreach ($rings as $ring) {
            if (!is_array($ring)) {
                continue;
            }
            $boundary = $doc->createElement($outer ? 'outerBoundaryIs' : 'innerBoundaryIs');
            $linear = $doc->createElement('LinearRing');
            $linear->appendChild($this->text($doc, 'coordinates', self::positions($ring)));
            $boundary->appendChild($linear);
            $polygon->appendChild($boundary);
            $outer = false;
        }

        return $polygon;
    }

    /**
     * @param mixed $parts
     */
    private function multi(\DOMDocument $doc, string $tag, mixed $parts, bool $asPolygon): \DOMElement
    {
        $node = $doc->createElement($tag);
        if (!is_array($parts)) {
            return $node;
        }

        foreach ($parts as $part) {
            $node->appendChild($asPolygon
                ? $this->polygon($doc, $part)
                : $this->flat($doc, 'LineString', $part));
        }

        return $node;
    }

    /**
     * GeoJSON nests coordinates as [[lon,lat],[lon,lat]]; KML wants a flat
     * whitespace-separated string of comma-separated pairs.
     */
    private static function positions(mixed $node): string
    {
        if (!is_array($node) || $node === []) {
            return '';
        }

        $first = $node[array_key_first($node)];
        if (!is_array($first)) {
            // Already a single position.
            $parts = [];
            foreach ($node as $value) {
                $parts[] = is_scalar($value) ? (string) $value : '';
            }
            return implode(',', $parts);
        }

        $tuples = [];
        foreach ($node as $child) {
            $rendered = self::positions($child);
            if ($rendered !== '') {
                $tuples[] = $rendered;
            }
        }

        return implode(' ', $tuples);
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $row
     */
    private function describe(array $row): string
    {
        $lines = [
            sprintf('id: %s', (string) ($row['id'] ?? '')),
            sprintf('status: %s', (string) ($row['status'] ?? '')),
        ];
        if (!empty($row['provenance'])) {
            $lines[] = sprintf('provenance: %s', (string) $row['provenance']);
        }

        $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];
        foreach ($attributes as $key => $value) {
            $lines[] = sprintf('%s: %s', (string) $key, is_scalar($value) ? (string) $value : (string) json_encode($value));
        }

        return implode("\n", $lines);
    }

    public function filename(ExportDataset $dataset): string
    {
        return sprintf('layer_%d_features.kml', $dataset->layerId);
    }
}
