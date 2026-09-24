<?php
declare(strict_types=1);

namespace Tests\Api;

use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\TestCase;

/**
 * TASK-108 — Signed download and classification enforcement acceptance.
 *
 * ACs covered here:
 *  - A minted link downloads the exact uploaded bytes once.
 *  - An expired or reused (single-use) link fails.
 *  - A tampered link fails.
 *  - An unauthorised classification returns NOT_FOUND (no existence leak).
 *  - Restricted downloads are audited (FR-150).
 */
class DocumentAccessTest extends TestCase
{
    private \PDO $pdo;
    private string $token;
    private int $userId;
    private string $storageDir = '/tmp/doc-access-test-storage';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = $this->pdo();
        $this->pdo->exec("DELETE FROM app.users WHERE username = 'acc_no_restricted'");
        putenv('DOCUMENTS_STORAGE_DIR=' . $this->storageDir);
        $_ENV['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $_SERVER['DOCUMENTS_STORAGE_DIR'] = $this->storageDir;
        $user = $this->createMockUser($this->pdo, ['document.view', 'document.upload'], ['GIS_EDITOR']);
        $this->token = $user['token'];
        $this->userId = $user['id'];
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM app.document_download_tokens WHERE document_id IN (SELECT id FROM app.documents WHERE original_filename LIKE 'ACC_%')");
        $this->pdo->exec("DELETE FROM app.documents WHERE original_filename LIKE 'ACC_%'");
        foreach (glob($this->storageDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storageDir);
        parent::tearDown();
    }

    private function uploadRequest(string $filename, string $content, string $accessLevel = 'INTERNAL'): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new \Slim\Psr7\Factory\ServerRequestFactory();
        $request = $factory->createServerRequest('POST', '/api/v1/documents')
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Content-Type', 'multipart/form-data')
            ->withHeader('Accept', 'application/json')
            ->withParsedBody(['access_level' => $accessLevel]);

        $stream = (new StreamFactory())->createStreamFromResource(
            fopen('data://text/plain,' . rawurlencode($content), 'rb')
        );
        $uploaded = new UploadedFile($stream, $filename, 'application/octet-stream', strlen($content), UPLOAD_ERR_OK);
        return $request->withUploadedFiles(['file' => $uploaded]);
    }

    private function upload(string $filename, string $content, string $accessLevel = 'INTERNAL'): array
    {
        $res = $this->handle($this->uploadRequest($filename, $content, $accessLevel));
        $this->assertContains($res->getStatusCode(), [200, 201], (string) $res->getBody());
        return json_decode((string) $res->getBody(), true)['data'];
    }

    private function mint(string $docId): array
    {
        $res = $this->handle(
            $this->createJsonRequest('POST', "/api/v1/documents/{$docId}/download-token", [])
                ->withHeader('Authorization', 'Bearer ' . $this->token)
        );
        $this->assertContains($res->getStatusCode(), [200, 201], (string) $res->getBody());
        return json_decode((string) $res->getBody(), true)['data'];
    }

    private function download(string $docId, string $token): \Psr\Http\Message\ResponseInterface
    {
        return $this->handle(
            $this->createJsonRequest('GET', "/api/v1/documents/{$docId}/download?token=" . rawurlencode($token), [])
                ->withHeader('Authorization', 'Bearer ' . $this->token)
        );
    }

    public function testSignedDownloadReturnsExactBytesOnce(): void
    {
        $content = "%PDF-1.4\nSigned download round trip.\n";
        $doc = $this->upload('ACC_roundtrip.pdf', $content);
        $signed = $this->mint($doc['id']);

        $res = $this->download($doc['id'], $signed['token']);
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame($content, (string) $res->getBody());
        $this->assertSame('application/pdf', $res->getHeaderLine('Content-Type'));

        // Single use: the second attempt with the same token fails.
        $res2 = $this->download($doc['id'], $signed['token']);
        $this->assertSame(404, $res2->getStatusCode(), 'Reused link must fail');
    }

    public function testExpiredTokenFails(): void
    {
        $doc = $this->upload('ACC_expired.pdf', "%PDF-1.4\nexpired\n");

        // Mint a token, then force it into the past server-side.
        $signed = $this->mint($doc['id']);
        [$docId, $expires, $nonce, $sig] = explode('.', $signed['token']);
        $this->pdo->prepare("UPDATE app.document_download_tokens SET expires_at = CURRENT_TIMESTAMP - interval '1 minute' WHERE document_id = :doc AND nonce = :nonce")
            ->execute([':doc' => $doc['id'], ':nonce' => $nonce]);

        $res = $this->download($doc['id'], $signed['token']);
        $this->assertSame(404, $res->getStatusCode(), 'Expired link must fail');
    }

    public function testTamperedTokenFails(): void
    {
        $doc = $this->upload('ACC_tamper.pdf', "%PDF-1.4\ntamper\n");
        $signed = $this->mint($doc['id']);

        $parts = explode('.', $signed['token']);
        $parts[3] = str_repeat('0', strlen($parts[3])); // clobber the HMAC
        $res = $this->download($doc['id'], implode('.', $parts));
        $this->assertSame(404, $res->getStatusCode(), 'Tampered link must fail');
    }

    public function testUnauthorisedRestrictedDownloadReturns404(): void
    {
        // The shared 'testuser' fixture accumulates roles across runs (and
        // SYS_ADMIN holds document.download_restricted), so mint a dedicated
        // caller with ONLY document.view/upload.
        $this->pdo->exec("INSERT INTO app.users (username, email, password_hash, full_name, org_id, status, version)
            VALUES ('acc_no_restricted', 'acc_no_restricted@example.com', 'dummy', 'No Restricted',
                    (SELECT id FROM app.organizations WHERE code = 'TESTORG'), 'ACTIVE', 1)");
        $uid = (int) $this->pdo->query("SELECT id FROM app.users WHERE username = 'acc_no_restricted'")->fetchColumn();
        $roleId = (int) $this->pdo->query("SELECT id FROM app.roles WHERE code = 'GIS_EDITOR'")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.user_roles (user_id, role_id) VALUES ($uid, $roleId)");
        $noRestrictedToken = \Firebase\JWT\JWT::encode([
            'sub' => (string) $uid, 'v' => 1, 'exp' => time() + 3600,
        ], getenv('JWT_SECRET') ?: 'dummy_secret', 'HS256');

        $doc = $this->upload('ACC_restricted.pdf', "%PDF-1.4\nrestricted\n", 'RESTRICTED');

        // Minting a token already fails with 404 (existence hidden).
        $res = $this->handle(
            $this->createJsonRequest('POST', "/api/v1/documents/{$doc['id']}/download-token", [])
                ->withHeader('Authorization', 'Bearer ' . $noRestrictedToken)
        );
        $this->assertSame(404, $res->getStatusCode(), (string) $res->getBody());
    }

    public function testRestrictedDownloadWithPermissionSucceedsAndIsAudited(): void
    {
        // Caller WITH document.download_restricted.
        $this->pdo->exec("INSERT INTO app.permissions (code, description) VALUES ('document.download_restricted', 'Download restricted docs') ON CONFLICT DO NOTHING");
        $permId = (int) $this->pdo->query("SELECT id FROM app.permissions WHERE code = 'document.download_restricted'")->fetchColumn();
        $roleId = (int) $this->pdo->query("SELECT role_id FROM app.user_roles WHERE user_id = {$this->userId} LIMIT 1")->fetchColumn();
        $this->pdo->exec("INSERT INTO app.role_permissions (role_id, permission_id) VALUES ($roleId, $permId) ON CONFLICT DO NOTHING");

        $content = "%PDF-1.4\nrestricted but authorised\n";
        $doc = $this->upload('ACC_restricted_ok.pdf', $content, 'RESTRICTED');
        $signed = $this->mint($doc['id']);

        $res = $this->download($doc['id'], $signed['token']);
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame($content, (string) $res->getBody());

        // FR-150: the restricted download is audited.
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM audit.audit_logs WHERE entity_type = 'app.documents' AND entity_id = :id AND action = 'SELECT'"
        );
        $stmt->execute([':id' => $doc['id']]);
        $this->assertGreaterThan(0, (int) $stmt->fetchColumn(), 'Restricted download must be audited');
    }
}
