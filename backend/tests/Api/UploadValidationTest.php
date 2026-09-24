<?php
declare(strict_types=1);

namespace Tests\Api;

use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

/**
 * TASK-107 — Document upload, storage, linking acceptance.
 *
 * ACs covered here:
 *  - A disguised executable is rejected (magic-byte + MIME sniff).
 *  - Identical files de-duplicate (same document row, de_duplicated=true).
 *  - No filesystem path / storage key is ever exposed.
 *  - Disallowed extensions and empty files are rejected.
 *  - Documents link to entities.
 */
class UploadValidationTest extends TestCase
{
    private \PDO $pdo;
    private string $token;
    private string $storageDir = '/tmp/doc-test-storage';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        putenv('DOCUMENTS_STORAGE_DIR=' . $this->storageDir);
        $_ENV['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $_SERVER['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $user = $this->createMockUser($this->pdo, ['parcel.view', 'document.view', 'document.upload'], ['GIS_EDITOR']);
        $this->token = $user['token'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM app.document_links WHERE document_id IN (SELECT id FROM app.documents WHERE original_filename LIKE 'UP_%')");
        $this->pdo->exec("DELETE FROM app.document_download_tokens WHERE document_id IN (SELECT id FROM app.documents WHERE original_filename LIKE 'UP_%')");
        $this->pdo->exec("DELETE FROM app.documents WHERE original_filename LIKE 'UP_%'");
        foreach (glob($this->storageDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storageDir);
        parent::tearDown();
    }

    /**
     * Build a POST /documents request with an in-memory uploaded file.
     *
     * @param array<string,string> $fields
     */
    private function uploadRequest(string $filename, string $content, array $fields = []): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new \Slim\Psr7\Factory\ServerRequestFactory();
        $streamFactory = new StreamFactory();

        $request = $factory->createServerRequest('POST', '/api/v1/documents')
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Content-Type', 'multipart/form-data')
            ->withHeader('Accept', 'application/json')
            ->withParsedBody($fields);

        $stream = $streamFactory->createStreamFromResource(
            fopen('data://text/plain,' . rawurlencode($content), 'rb')
        );
        $uploaded = new UploadedFile(
            $stream,
            $filename,
            'application/octet-stream',
            (int) strlen($content),
            UPLOAD_ERR_OK,
        );

        return $request->withUploadedFiles(['file' => $uploaded]);
    }

    public function testValidPdfUploadSucceedsAndHidesStoragePath(): void
    {
        $pdf = "%PDF-1.4\n%Test content for upload.\n";
        $res = $this->handle($this->uploadRequest('UP_plan.pdf', $pdf, ['access_level' => 'INTERNAL']));
        $this->assertContains($res->getStatusCode(), [200, 201], (string) $res->getBody());

        $data = json_decode((string) $res->getBody(), true)['data'];
        $this->assertArrayHasKey('id', $data);
        $this->assertSame('application/pdf', $data['mime_type']);
        $this->assertArrayNotHasKey('storage_key', $data, 'Storage key must never be exposed');
        $this->assertStringNotContainsString('/tmp', (string) json_encode($data), 'No filesystem path may leak');
    }

    public function testDisguisedExecutableIsRejected(): void
    {
        // An ELF binary dressed up as a .pdf.
        $elf = "\x7FELF\x02\x01\x01\x00" . str_repeat("\x00", 32) . 'malicious payload';
        $res = $this->handle($this->uploadRequest('UP_evil.pdf', $elf));
        $this->assertSame(422, $res->getStatusCode(), (string) $res->getBody());
        $body = json_decode((string) $res->getBody(), true);
        $this->assertSame('VALIDATION_FAILED', $body['error']['code']);

        // And nothing was stored.
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM app.documents WHERE original_filename = 'UP_evil.pdf'")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testDisallowedExtensionIsRejected(): void
    {
        $res = $this->handle($this->uploadRequest('UP_script.sh', "#!/bin/sh\necho hi\n"));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame('VALIDATION_FAILED', json_decode((string) $res->getBody(), true)['error']['code']);
    }

    public function testEmptyFileIsRejected(): void
    {
        $res = $this->handle($this->uploadRequest('UP_empty.png', ''));
        $this->assertSame(422, $res->getStatusCode());
    }

    public function testIdenticalFilesDeDuplicate(): void
    {
        $png = "\x89PNG\r\n\x1A\n" . str_repeat('A', 512);

        $first = json_decode((string) $this->handle($this->uploadRequest('UP_first.png', $png))->getBody(), true)['data'];
        $second = json_decode((string) $this->handle($this->uploadRequest('UP_second.png', $png))->getBody(), true)['data'];

        $this->assertFalse((bool) ($first['de_duplicated'] ?? false));
        $this->assertTrue((bool) ($second['de_duplicated'] ?? false), 'Second identical upload de-duplicates');
        $this->assertSame($first['id'], $second['id'], 'Both uploads reference the same stored blob');
        $this->assertSame($first['sha256'], $second['sha256']);
    }

    public function testUploadLinksToEntity(): void
    {
        $pdf = "%PDF-1.4\n%Link test.\n";
        $data = json_decode((string) $this->handle($this->uploadRequest('UP_linked.pdf', $pdf, [
            'entity_type' => 'PARCEL',
            'entity_id'   => '11111111-1111-1111-1111-111111111111',
            'link_role'   => 'TITLE_SCAN',
        ]))->getBody(), true)['data'];

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM app.document_links WHERE document_id = :id AND entity_type = 'PARCEL' AND link_role = 'TITLE_SCAN'");
        $stmt->execute([':id' => $data['id']]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
