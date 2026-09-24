<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * TASK-108 — Single-use signed download tokens.
 *
 * Each minted link gets a row here; consumption atomically claims it
 * (consumed_at set), so a reused or expired link fails even within the
 * signature's validity window.
 */
final class CreateDocumentDownloadTokens extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            CREATE TABLE IF NOT EXISTS app.document_download_tokens (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                document_id uuid NOT NULL REFERENCES app.documents(id),
                nonce varchar(32) NOT NULL,
                expires_at timestamptz NOT NULL,
                consumed_at timestamptz NULL,
                user_id bigint NULL,
                created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uq_doc_token_nonce UNIQUE (document_id, nonce)
            )
        ");
        $this->execute("CREATE INDEX IF NOT EXISTS idx_doc_token_doc ON app.document_download_tokens (document_id)");
    }

    public function down(): void
    {
        $this->execute("DROP TABLE IF EXISTS app.document_download_tokens");
    }
}
