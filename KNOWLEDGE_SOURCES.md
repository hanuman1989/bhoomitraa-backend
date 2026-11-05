# Knowledge Sources MVP

This module uses the installed Laravel 12 framework, existing admin bearer-token authentication, and the application-wide status/data/pagination/message JSON envelope. Validation responses also contain errors; authorized document downloads stream the file instead.

## Access and workflow

All endpoints are under /api/admin/knowledge-sources. Active admins pass the manage-knowledge-sources gate. Reviews additionally require review-knowledge-sources, currently granted to active super users. These separate gates can be extended when resource permissions are introduced.

1. POST / creates a pending, inactive draft from publication metadata. A locator is optional at this stage.
2. POST /{knowledgeSource}/attachments accepts an attachments[] multipart array: up to 10 PDF, DOCX, or XLSX files, each at most 10 MiB. Content MIME types and filename extensions are validated.
3. POST /{knowledgeSource}/review accepts action: verify, reject, outdate, or reopen; optional review_notes; mandatory rejection_reason for rejection; and mandatory review_notes for outdating. Verification requires a URL, a nonempty reference identifier, or an attachment whose file still exists. Only verification accepts an optional future next_review_at.
4. PATCH /{knowledgeSource}/status accepts boolean is_active. Activation requires verification and a usable locator. Reviews never automatically activate a source.

GET /, GET /{knowledgeSource}, PATCH /{knowledgeSource}, and DELETE /{knowledgeSource} provide ordinary CRUD. GET and DELETE /{knowledgeSource}/attachments/{attachment} download and remove documents. Nested binding returns 404 for attachments belonging to another source.

Ordinary metadata updates ignore client-supplied review, activation, and audit fields. Changed titles, publishers, classifications, publication dates/years, language, identifiers, descriptions, URLs, or attachment evidence reset review to pending and deactivate the source. Unchanged values and license-note-only updates preserve verification. Omitted attachments remain unchanged.

The table stores **only the current review state**, not review history. For verify, reject, and outdate, verified_by and verified_at identify the reviewer and time of the current decision; last_checked_at is the same check time. Reopen and material changes clear that decision and stale review schedules/rejection. Reopen can retain newly supplied review notes. No audit log infrastructure existed to integrate.

Review timestamps are written in UTC. next_review_at accepts timezone offsets and is normalized to UTC; values without an offset use the application's configured timezone. Resources serialize timestamps as ISO 8601, and added_at uses j M Y h:i A with existing application timezone behavior.

## Metadata and listings

Required fields: title (500 characters), publisher (255), and source_type. Optional metadata: source_url (HTTP/HTTPS), language, published_at (Y-m-d), publication_year (1000 through next year), reference_identifier, identifier_type, description, and license_notes.

- Source types: government_publication, research_paper, journal_article, report, book, manual, guideline, website, other.
- Identifier types: doi, isbn, issn, report_number, other. Identifiers are publication-provided references; no external resolution or authenticity check occurs.
- Languages: the shared config/languages.php supported list, currently en and hi. Metadata stays in its original language; there are no translation tables.

List filters: keyword (literal title/publisher/reference search), source_type, verification_status, language, is_active, and review_due. A due review has a non-null schedule at or before the current time; review_due=0 includes unscheduled and future reviews.

Sorting uses sort_by=created_at|title|publisher|published_at|publication_year|next_review_at and sort_direction=asc|desc, defaulting to newest first, with ID as a stable secondary sort. Pagination uses positive page/per_page, defaults to 15, caps at 100, and preserves allowed filters in links.

## Retention and file lifecycle

Active sources cannot be deleted (409). Inactive sources can be explicitly deleted when no restricting foreign-key references exist. Outdating or rejecting never deletes a source. Removing the final usable locator of a verified source returns 409; reopen it before intentionally removing that evidence.

The inspected application has no agricultural-content, source-link, farmer-history, or attachment-evidence relationships. No such tables or integrations are invented by this module. Future consumers must use restrictive foreign keys and extend source/attachment retention checks before introducing historical references, particularly polymorphic or non-FK references. They must not cascade-delete retained evidence.

Parent deletion explicitly deletes each attachment through Eloquent inside the transaction. The registered observer implements ShouldHandleEventsAfterCommit; rollback preserves files, and nested commits wait for the outermost commit. Failed uploads remove files written by that request, including partially stored files.

The default private disk stores documents in storage/app/knowledge-source-documents, outside public storage, with serving disabled. KNOWLEDGE_SOURCES_DISK can select another **private** disk. Keep that selection stable for existing attachments because the approved schema does not store a disk per record. Resources never expose file_path; downloads use authenticated API routes.

Cleanup is synchronous after commit and is not a durable job. Missing files are safe. Filesystem failures are logged by attachment ID without exposing paths or storage exceptions to consumers. A filesystem outage or process crash after commit may leave an orphan file requiring operator cleanup; no retry worker or new cleanup tables are introduced.

## Deployment and verification

Run php artisan migrate --no-interaction to install the two new tables. No existing content is converted. Rollback drops attachments before sources and permanently removes their database data; migration rollback does not dispatch model observers, so archive or clean private documents separately when intentionally uninstalling populated tables.

Configure PHP/web-server upload and request-size limits to accommodate multipart batch sizes. Frontend confirmation for significant status changes remains the separate Next.js application's responsibility.

Focused checks:

    php artisan test --compact tests/Feature/KnowledgeSourceControllerTest.php tests/Feature/KnowledgeSourceAttachmentTest.php
    php artisan route:list --path=api/admin/knowledge-sources --except-vendor --no-interaction
    php artisan migrate --pretend --no-interaction
