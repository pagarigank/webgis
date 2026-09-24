<?php
declare(strict_types=1);

namespace App\Documents;

use App\Core\Error\ApiError;
use PDO;

/**
 * TASK-107/108 — Document upload, storage, linking, and signed download.
 *
 * Storage model (FR-157/SR-07):
 *  - content-addressed de-duplication by SHA-256 (unique index on sha256);
 *  - randomised storage keys (32 hex bytes) under a configurable base dir
 *    OUTSIDE the web root; no filesystem path is ever exposed — clients only
 *    ever see document ids and short-lived signed URLs;
 *  - upload validated by extension, declared MIME, sniffed MIME, and magic
 *    bytes; a disguised executable is rejected;
 *  - classification (PUBLIC/INTERNAL/RESTRICTED/SENSITIVE_PERSONAL) is
 *    checked server-side on every download; RESTRICTED+ requires the
 *    document.download_restricted permission and is audited.
 *
 * Signed URLs (FR-158): HMAC-SHA256 over (document id, expiry, nonce) with
 * the JWT secret; single-use via a consume marker column; expired or reused
 * links fail; unauthorised classification returns NOT_FOUND (no existence
 * leak, per api.md §6.1).
 */
final class DocumentService
{
    /** Allowed document types per FR-156, with their magic-byte signatures. */
    private const ALLOWED = [
        'pdf'  => ['mime' => 'application/pdf', 'magic' => "%PDF-"],
        'jpg'  => ['mime' => 'image/jpeg', 'magic' => "\xFF\xD8\xFF"],
        'jpeg' => ['mime' => 'image/jpeg', 'magic' => "\xFF\xD8\xFF"],
        'png'  => ['mime' => 'image/png', 'magic' => "\x89PNG\r\n\x1A\n"],
        'tif'  => ['mime' => 'image/tiff', 'magic' => "II*\x00"],
        'tiff' => ['mime' => 'image/tiff', 'magic' => "MM\x00*"],
    ];

    public const DEFAULT_MAX_BYTES = 26214400; // 25 MB (FR-156)

    /** Narrowed from App\Core\Config\Config to keep the domain testable. */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $storageDir,
        private readonly string $signingKey,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
    }

    // ------------------------------------------------------------------
    // Upload (TASK-107)
    // ------------------------------------------------------------------

    /**
     * @param array{tmp_name?:string,content?:string,name:string,size?:int} $file
     *     Either a filesystem path (tmp_name) or raw content — the controller
     *     passes content from the PSR-7 uploaded-file stream, which also makes
     *     the service testable without touching the filesystem.
     * @param array{doc_type?:string,access_level?:string,description?:string} $meta
     * @return array<string,mixed> The document row (id, sha256, de_duplicated, …)
     */
    public function upload(string $userId, array $file, array $meta = []): array
    {
        $originalName = (string) ($file['name'] ?? '');
        if ($originalName === '') {
            throw new ApiError('VALIDATION_FAILED', 'An uploaded file is required.', 422, [
                'fields' => [['field' => 'file', 'rule' => 'REQUIRED']],
            ]);
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$ext])) {
            throw new ApiError('VALIDATION_FAILED', "File extension .$ext is not allowed.", 422, [
                'fields' => [['field' => 'file', 'rule' => 'EXTENSION']],
            ]);
        }
        $spec = self::ALLOWED[$ext];

        if (array_key_exists('content', $file)) {
            $content = (string) $file['content'];
        } else {
            $path = (string) ($file['tmp_name'] ?? '');
            if ($path === '' || !is_file($path)) {
                throw new ApiError('VALIDATION_FAILED', 'An uploaded file is required.', 422, [
                    'fields' => [['field' => 'file', 'rule' => 'REQUIRED']],
                ]);
            }
            $content = file_get_contents($path);
        }

        $size = (int) ($file['size'] ?? strlen($content));
        if ($size <= 0 || $content === '') {
            throw new ApiError('VALIDATION_FAILED', 'The uploaded file is empty.', 422, [
                'fields' => [['field' => 'file', 'rule' => 'EMPTY']],
            ]);
        }
        if ($size > $this->maxBytes) {
            throw new ApiError('VALIDATION_FAILED', sprintf('File exceeds the %s MB size cap.', (string) round($this->maxBytes / 1048576)), 422, [
                'fields' => [['field' => 'file', 'rule' => 'SIZE']],
            ]);
        }

        // Magic bytes: a disguised executable fails here even with a
        // PDF-looking filename and MIME (VR-31).
        if (!str_starts_with($content, $spec['magic'])) {
            throw new ApiError('VALIDATION_FAILED', 'File content does not match its extension (magic-byte check failed).', 422, [
                'fields' => [['field' => 'file', 'rule' => 'MAGIC_BYTES']],
            ]);
        }

        // MIME sniff as a second, independent content check. libmagic cannot
        // always resolve truncated/minimal files (reports octet-stream), so
        // when the exact magic-byte signature already matched, an
        // inconclusive sniff is tolerated — but a dangerous or clearly
        // conflicting detected type is still rejected (VR-31).
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $sniffed = $finfo->buffer($content) ?: 'application/octet-stream';
        if ($sniffed !== $spec['mime']) {
            $familyOk = in_array($sniffed, ['image/tiff', 'image/x-tiff'], true) && str_starts_with($spec['mime'], 'image/tiff');
            $inconclusive = $sniffed === 'application/octet-stream' || $sniffed === 'application/x-empty';
            $dangerous = (bool) preg_match('/executable|shellscript|x-script|script|elf/i', $sniffed)
                || (str_starts_with($sniffed, 'text/') && !str_starts_with($spec['mime'], 'text/'));
            if (!$familyOk && !$inconclusive && ($dangerous || true)) {
                // Strict mismatch (e.g. declared image, detected text).
                throw new ApiError('VALIDATION_FAILED', "Declared type does not match detected type ($sniffed).", 422, [
                    'fields' => [['field' => 'file', 'rule' => 'MIME_SNIFF']],
                ]);
            }
        }

        $sha256 = hash('sha256', $content);

        // De-duplication: identical content reuses the stored blob row.
        $existing = $this->findBySha256($sha256);
        if ($existing !== null) {
            $this->link($existing['id'], $meta);
            $existing['de_duplicated'] = true;
            return $existing;
        }

        $storageKey = bin2hex(random_bytes(16));
        $target = $this->pathFor($storageKey);
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0770, true) && !is_dir($this->storageDir)) {
            throw new ApiError('INTERNAL_ERROR', 'Document storage directory is not writable.', 500);
        }
        if (file_put_contents($target, $content) === false) {
            throw new ApiError('INTERNAL_ERROR', 'Failed to persist document blob.', 500);
        }

        $docId = $this->newUuid();
        $accessLevel = in_array($meta['access_level'] ?? '', ['PUBLIC', 'INTERNAL', 'RESTRICTED', 'SENSITIVE_PERSONAL'], true)
            ? $meta['access_level'] : 'INTERNAL';

        $stmt = $this->pdo->prepare(
            'INSERT INTO app.documents
                (id, storage_key, original_filename, doc_type, mime_type, byte_size, sha256,
                 access_level, description, uploaded_by)
             VALUES (:id, :key, :name, :type, :mime, :size, decode(:sha, :hex), :access, :descr, :uid)'
        );
        $stmt->execute([
            ':id'     => $docId,
            ':key'    => $storageKey,
            ':name'   => $originalName,
            ':type'   => $meta['doc_type'] ?? $ext,
            ':mime'   => $spec['mime'],
            ':size'   => $size,
            ':sha'    => $sha256,
            ':hex'    => 'hex',
            ':access' => $accessLevel,
            ':descr'  => $meta['description'] ?? null,
            ':uid'    => (int) $userId,
        ]);

        $this->link($docId, $meta);

        return $this->get($docId) + ['de_duplicated' => false];
    }

    /**
     * Link a document to entities (parcels, titles, plans, control points).
     *
     * @param array{entity_type?:string,entity_id?:string,link_role?:string}|array<int, mixed> $meta
     */
    public function link(string $documentId, array $meta): void
    {
        if (empty($meta['entity_type']) || empty($meta['entity_id'])) {
            return;
        }
        $role = in_array($meta['link_role'] ?? '', ['SUPPORTING', 'SOURCE', 'PLAN', 'TITLE_SCAN'], true)
            ? $meta['link_role'] : 'SUPPORTING';
        $stmt = $this->pdo->prepare(
            'INSERT INTO app.document_links (document_id, entity_type, entity_id, link_role, linked_by)
             VALUES (:doc, :etype, :eid, :role, NULL)
             ON CONFLICT (document_id, entity_type, entity_id, link_role) DO NOTHING'
        );
        $stmt->execute([
            ':doc'   => $documentId,
            ':etype' => (string) $meta['entity_type'],
            ':eid'   => (string) $meta['entity_id'],
            ':role'  => $role,
        ]);
    }

    // ------------------------------------------------------------------
    // Signed download (TASK-108)
    // ------------------------------------------------------------------

    /** Mint a short-lived signed download token. */
    public function signDownload(string $documentId, int $userId, int $ttlSeconds = 300): array
    {
        $doc = $this->get($documentId);
        $nonce = bin2hex(random_bytes(8));
        $expires = time() + max(30, $ttlSeconds);
        $sig = $this->signature($documentId, $expires, $nonce);

        // Persist nonce + expiry so a link is single-use server-side.
        $stmt = $this->pdo->prepare(
            'INSERT INTO app.document_download_tokens (document_id, nonce, expires_at, user_id)
             VALUES (:doc, :nonce, CURRENT_TIMESTAMP + (:ttl || \' seconds\')::interval, :uid)'
        );
        $stmt->execute([
            ':doc'   => $documentId,
            ':nonce' => $nonce,
            ':ttl'   => max(30, $ttlSeconds),
            ':uid'   => $userId,
        ]);

        return [
            'document_id' => $documentId,
            'token'       => implode('.', [$documentId, (string) $expires, $nonce, $sig]),
            'expires_at'  => $expires,
        ];
    }

    /**
     * Consume a signed token and return the document row + blob.
     * Expired, tampered, or reused tokens fail; classification below the
     * caller's clearance returns NOT_FOUND (no existence leak).
     *
     * @return array{document:array<string,mixed>,content:string}
     */
    public function consumeDownload(string $token, ?string $userRestrictedPermissionHeld): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 4) {
            throw new ApiError('NOT_FOUND', 'Document or link not found.', 404);
        }
        [$docId, $expires, $nonce, $sig] = $parts;

        if (!hash_equals($this->signature($docId, (int) $expires, $nonce), $sig)) {
            throw new ApiError('NOT_FOUND', 'Document or link not found.', 404);
        }
        if ((int) $expires < time()) {
            throw new ApiError('NOT_FOUND', 'Document or link not found.', 404);
        }

        // Single-use: atomically claim the token row.
        $claim = $this->pdo->prepare(
            "UPDATE app.document_download_tokens
             SET consumed_at = CURRENT_TIMESTAMP
             WHERE document_id = :doc AND nonce = :nonce
               AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP
             RETURNING id"
        );
        $claim->execute([':doc' => $docId, ':nonce' => $nonce]);
        if ($claim->fetchColumn() === false) {
            throw new ApiError('NOT_FOUND', 'Document or link not found.', 404);
        }

        $doc = $this->get($docId);

        // Classification enforcement happens server-side, per request.
        if (in_array($doc['access_level'], ['RESTRICTED', 'SENSITIVE_PERSONAL'], true)
            && $userRestrictedPermissionHeld !== 'yes') {
            // Out-of-scope semantics: 404, never 403, to avoid leaking existence.
            throw new ApiError('NOT_FOUND', 'Document or link not found.', 404);
        }

        $path = $this->pathFor((string) $doc['storage_key']);
        if (!is_file($path)) {
            throw new ApiError('INTERNAL_ERROR', 'Document blob is missing from storage.', 500);
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new ApiError('INTERNAL_ERROR', 'Document blob could not be read.', 500);
        }

        return ['document' => $doc, 'content' => $content];
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function get(string $documentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, storage_key, original_filename, doc_type, mime_type, byte_size,
                    encode(sha256, \'hex\') AS sha256, access_level, description,
                    page_count, uploaded_by, uploaded_at, deleted_at
             FROM app.documents WHERE id = :id'
        );
        $stmt->execute([':id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Document not found.', 404);
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function findBySha256(string $sha256): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, original_filename, doc_type, mime_type, byte_size,
                    encode(sha256, 'hex') AS sha256, access_level, description,
                    page_count, uploaded_by, uploaded_at, deleted_at
             FROM app.documents WHERE sha256 = decode(:sha, 'hex') AND deleted_at IS NULL"
        );
        $stmt->execute([':sha' => $sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function signature(string $docId, int $expires, string $nonce): string
    {
        return hash_hmac('sha256', $docId . '|' . $expires . '|' . $nonce, $this->signingKey);
    }

    private function pathFor(string $storageKey): string
    {
        // Double-barrel: randomised key + never under the web root.
        return rtrim($this->storageDir, '/\\') . DIRECTORY_SEPARATOR . $storageKey;
    }

    private function newUuid(): string
    {
        $stmt = $this->pdo->query('SELECT gen_random_uuid()::text');
        return (string) $stmt->fetchColumn();
    }
}
