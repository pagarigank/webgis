<?php
declare(strict_types=1);

namespace App\ImportExport\Domain\Writer;

use App\ImportExport\Domain\ExportDataset;

/**
 * TASK-127 — one export serialiser.
 *
 * A writer is handed a fully-prepared dataset and returns the bytes plus the
 * two descriptors the HTTP layer needs. It performs no queries, no permission
 * checks and no PII decisions; anything a writer needs to know must already be
 * on the dataset.
 */
interface ExportWriter
{
    /** Uppercase format code this writer answers to, e.g. `GEOJSON`. */
    public function format(): string;

    /**
     * The CRS codes this format is allowed to be written in.
     *
     * GeoJSON (RFC 7946) and KML both mandate WGS 84, so those writers return
     * `null` to mean "the caller may not choose". Shapefile and GeoPackage
     * carry their own CRS and return the registry code they default to.
     */
    public function allowedCrs(): ?string;

    /** @return array{content_type:string, body:string} */
    public function write(ExportDataset $dataset): array;

    /** Filename offered to the browser, without a directory component. */
    public function filename(ExportDataset $dataset): string;
}
