-- Batch 4: independent, auditable recipient records and recoverable new links.
-- Apply AFTER the Batch 1 scoring migration and BEFORE deploying Batch 4 PHP.
-- Existing token hashes are deliberately not converted; no plaintext token can be recovered from them.
-- No existing invitations or survey responses are deleted or altered.

ALTER TABLE client_survey_invitations
    ADD COLUMN IF NOT EXISTS token_ciphertext VARCHAR(255) NULL AFTER token_hash,
    ADD COLUMN IF NOT EXISTS link_scope ENUM('general', 'targeted') NULL AFTER token_ciphertext;

CREATE TABLE IF NOT EXISTS client_survey_invitation_recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invitation_id BIGINT UNSIGNED NOT NULL,
    recipient_email VARCHAR(254) NOT NULL,
    recipient_name VARCHAR(255) NOT NULL DEFAULT '',
    mail_status ENUM('not_requested', 'queued', 'processing', 'accepted', 'failed', 'uncertain') NOT NULL DEFAULT 'not_requested',
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) DEFAULT NULL,
    last_attempted_at DATETIME DEFAULT NULL,
    accepted_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_survey_invite_email (invitation_id, recipient_email),
    KEY idx_survey_invite_mail_status (invitation_id, mail_status),
    CONSTRAINT fk_survey_invite_recipients_invitation FOREIGN KEY (invitation_id)
        REFERENCES client_survey_invitations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
