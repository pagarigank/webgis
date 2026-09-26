<?php
declare(strict_types=1);

namespace App\Documents\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Request\JsonBodyParser;
use App\Core\Http\Response\Envelope;
use App\Documents\DocumentService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * TASK-107/108 — Documents API.
 *
 *  - POST   /documents                multipart upload (file + meta fields)
 *  - GET    /documents/{id}           metadata (no storage path, ever)
 *  - POST   /documents/{id}/links     link to an entity
 *  - POST   /documents/{id}/download-token   mint a short-lived signed URL
 *  - GET    /documents/{id}/download?token=…   consume it (single-use)
 *
 * Classification enforcement: minting a token for RESTRICTED /
 * SENSITIVE_PERSONAL requires document.download_restricted; the download
 * re-checks classification server-side (defense in depth).
 */
final class DocumentController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DocumentService $documents,
        private readonly \App\Audit\AuditWriter $audit,
    ) {
    }

    public function upload(Request $request, Response $response): Response
    {
        $uid = $this->requireUser($request);
        $this->scopeSession($uid);

        $files = $request->getUploadedFiles();
        if (empty($files['file'])) {
            throw new ApiError('VALIDATION_FAILED', 'A multipart "file" part is required.', 422, [
                'fields' => [['field' => 'file', 'rule' => 'REQUIRED']],
            ]);
        }
        /** @var \Psr\Http\Message\UploadedFileInterface $uploaded */
        $uploaded = $files['file'];
        if ($uploaded->getError() !== UPLOAD_ERR_OK) {
            throw new ApiError('VALIDATION_FAILED', 'The file upload failed.', 422);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $meta = [
            'doc_type'     => isset($body['doc_type']) ? (string) $body['doc_type'] : null,
            'access_level' => isset($body['access_level']) ? (string) $body['access_level'] : null,
            'description'  => isset($body['description']) ? (string) $body['description'] : null,
            'entity_type'  => isset($body['entity_type']) ? (string) $body['entity_type'] : null,
            'entity_id'    => isset($body['entity_id']) ? (string) $body['entity_id'] : null,
            'link_role'    => isset($body['link_role']) ? (string) $body['link_role'] : null,
        ];

        $doc = $this->documents->upload((string) $uid, [
            'content' => (string) $uploaded->getStream(),
            'name'    => $uploaded->getClientFilename() ?? 'upload',
            'size'    => $uploaded->getSize() ?? null,
        ], $meta);

        $this->audit->writeFromSession('INSERT', 'app.documents', (string) $doc['id'], null,
            ['filename' => $doc['original_filename'], 'sha256' => $doc['sha256'], 'access_level' => $doc['access_level']],
            null, 'Document uploaded');

        // No filesystem path or storage key is ever exposed (FR-157).
        unset($doc['storage_key']);

        return Envelope::success($response, $doc, 201);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $this->requireUser($request);
        $doc = $this->documents->get($this->uuid($args, 'id'));
        // Never expose the storage key or any filesystem path (FR-157).
        unset($doc['storage_key']);
        return Envelope::success($response, $doc, 200);
    }

    /**
     * List all documents linked to a given entity (e.g. parcel).
     * Route: GET /parcels/{id}/documents or GET /entities/{type}/{id}/documents.
     * The entity_type and entity_id are passed as route args or query params.
     */
    public function listForEntity(Request $request, Response $response, array $args): Response
    {
        $this->requireUser($request);
        $entityType = (string) ($args['entity_type'] ?? ($request->getQueryParams()['entity_type'] ?? 'parcel'));
        $entityId   = (string) ($args['id'] ?? '');

        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.original_filename, d.doc_type, d.mime_type, d.byte_size,
                    d.access_level, d.description, d.uploaded_at,
                    dl.link_role, dl.linked_at
               FROM app.document_links dl
               JOIN app.documents d ON d.id = dl.document_id
              WHERE dl.entity_type = :et
                AND dl.entity_id   = :eid
                AND d.deleted_at IS NULL
              ORDER BY dl.linked_at DESC'
        );
        $stmt->execute([':et' => $entityType, ':eid' => $entityId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return Envelope::success($response, $rows, 200);
    }

    public function link(Request $request, Response $response, array $args): Response
    {
        $uid = $this->requireUser($request);
        $this->scopeSession($uid);
        $docId = $this->uuid($args, 'id');
        $this->documents->get($docId);

        $body = JsonBodyParser::parse($request);
        if (empty($body['entity_type']) || empty($body['entity_id'])) {
            throw new ApiError('VALIDATION_FAILED', 'entity_type and entity_id are required.', 422);
        }
        $this->documents->link($docId, $body);

        $this->audit->writeFromSession('INSERT', 'app.document_links', $docId, null,
            ['entity_type' => $body['entity_type'], 'entity_id' => $body['entity_id']],
            null, 'Document linked');

        return Envelope::success($response, ['linked' => true], 201);
    }

    public function mintDownloadToken(Request $request, Response $response, array $args): Response
    {
        $uid = $this->requireUser($request);
        $this->scopeSession($uid);
        $docId = $this->uuid($args, 'id');

        $doc = $this->documents->get($docId);
        if (in_array($doc['access_level'], ['RESTRICTED', 'SENSITIVE_PERSONAL'], true)
            && !$this->hasPermission($uid, 'document.download_restricted')) {
            // Existence-avoiding 404 per api.md §6.1 scope semantics.
            throw new ApiError('NOT_FOUND', 'Document not found.', 404);
        }

        $signed = $this->documents->signDownload($docId, $uid);

        $this->audit->writeFromSession('SELECT', 'app.documents', $docId, null, null, null, 'Signed download link minted');

        return Envelope::success($response, $signed + ['download_url' => "/api/v1/documents/{$docId}/download?token={$signed['token']}"], 201);
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        $uid = $this->requireUser($request);
        $this->scopeSession($uid);
        $docId = $this->uuid($args, 'id');

        $token = (string) ($request->getQueryParams()['token'] ?? '');
        if ($token === '') {
            throw new ApiError('VALIDATION_FAILED', 'A download token is required.', 422);
        }

        $restricted = $this->hasPermission($uid, 'document.download_restricted') ? 'yes' : null;
        $result = $this->documents->consumeDownload($token, $restricted);

        // Consume must be for THIS document (token is bound to its id).
        if ((string) $result['document']['id'] !== $docId) {
            throw new ApiError('NOT_FOUND', 'Document not found.', 404);
        }

        $restrictedDoc = in_array($result['document']['access_level'], ['RESTRICTED', 'SENSITIVE_PERSONAL'], true);
        if ($restrictedDoc) {
            // FR-150: restricted downloads are audited.
            $this->audit->writeFromSession('SELECT', 'app.documents', $docId, null, null, null, 'Restricted document downloaded');
        }

        $response->getBody()->write($result['content']);

        return $response
            ->withHeader('Content-Type', (string) $result['document']['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename((string) $result['document']['original_filename']) . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    // ------------------------------------------------------------------

    private function hasPermission(int $userId, string $code): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM app.permissions p
             JOIN app.role_permissions rp ON p.id = rp.permission_id
             JOIN app.user_roles ur ON rp.role_id = ur.role_id
             WHERE ur.user_id = :uid AND p.code = :code'
        );
        $stmt->execute([':uid' => $userId, ':code' => $code]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function requireUser(Request $request): int
    {
        $userId = (int) ($request->getAttribute('user_id') ?: 0);
        if ($userId <= 0) {
            throw new ApiError('UNAUTHORIZED', 'Not authenticated', 401);
        }
        return $userId;
    }

    private function scopeSession(int $uid): void
    {
        $this->pdo->exec('SET LOCAL app.current_user_id = ' . (int) $uid);
    }

    private function uuid(array $args, string $key): string
    {
        $val = $args[$key] ?? '';
        if (!is_string($val) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid document id', 400);
        }
        return strtolower($val);
    }
}
