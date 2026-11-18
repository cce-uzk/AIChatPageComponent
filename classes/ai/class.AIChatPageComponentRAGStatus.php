<?php

/**
 * This file is part of the AIChatPageComponent plugin for ILIAS.
 *
 * Copyright (c) University of Cologne, CompetenceCenter E-Learning
 *
 * The plugin is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 */

declare(strict_types=1);

namespace ai;

/**
 * Processing state of chat files in the RAG service
 *
 * The RAG processes uploaded files asynchronously. The state is stored per
 * attachment and queried from the RAG by chat requests and by the cron job of the
 * plugin; the interval between two queries of a chat grows with the time since the
 * last upload. Whether a chat has unprocessed files is decided from the database
 * only. Files whose upload or processing failed are uploaded again with an
 * increasing delay, unless the RAG rejected them. Files the RAG could not delete
 * yet are deleted later.
 *
 * @author Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */
class AIChatPageComponentRAGStatus
{
    /** Local states in pcaic_attachments.rag_status */
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';
    /** Waiting for an upload: the first upload failed, or a request is uploading the file right now */
    public const PENDING = 'pending';
    /** Processed by the RAG, but the retrieval finds no content (e.g. a scan the text recognition could not read) */
    public const EMPTY = 'empty';
    /** Processed and usable, but the file is a scan: the content depends on the text recognition of the RAG */
    public const SCANNED = 'scanned';
    /** Shown to users only, never stored: failed and not uploaded again (rejected by the RAG or failed too often) */
    public const REJECTED = 'rejected';

    /** @var int Longest interval between two status checks of a chat, in seconds */
    public const CHECK_INTERVAL = 60;

    /** @var int Delay before the first new upload after a failure, in seconds */
    public const RETRY_BASE_DELAY = 30;

    /** @var int Maximum delay between new uploads, in seconds */
    public const RETRY_MAX_DELAY = 300;

    /** @var int A request that uploads a file keeps it for this number of seconds; afterwards another request may upload it */
    public const UPLOAD_CLAIM_TIME = 300;

    /** @var int No further file of a chat is queried after this number of seconds, the others follow with the next check */
    public const REFRESH_TIME_BUDGET = 20;

    /** @var int A file the RAG fails to process is given up after this number of attempts in a row */
    public const MAX_PROCESSING_ATTEMPTS = 5;

    /** @var int Delay before the first new attempt to delete a file, in seconds */
    public const DELETION_RETRY_BASE_DELAY = 300;

    /** @var int Maximum delay between attempts to delete a file, in seconds */
    public const DELETION_RETRY_MAX_DELAY = 21600;

    /** @var int Pending deletions are given up after this number of attempts */
    public const MAX_DELETION_ATTEMPTS = 100;

    /** @var int Pending deletions processed per run */
    private const DELETIONS_PER_RUN = 10;

    private const DELETION_CHECK_KEY = 'rag_deletions_checked_at';

    /** @var string Fingerprint of the RAG file types the attachments were last reconciled with */
    private const FILE_TYPES_KEY = 'rag_file_types_fingerprint';

    /**
     * Delay before the next attempt after $failed_count failures in a row
     */
    public static function retryDelay(
        int $failed_count,
        int $base_delay = self::RETRY_BASE_DELAY,
        int $max_delay = self::RETRY_MAX_DELAY
    ): int {
        $exponent = max(0, min($failed_count - 1, 20));
        return (int) min($base_delay * (2 ** $exponent), $max_delay);
    }

    /**
     * Delay before the next upload, or null if the file is given up
     *
     * @param int $max_attempts Number of failures in a row after which no new upload follows; 0 = unlimited
     */
    public static function nextRetryDelay(int $failed_count, int $max_attempts = 0): ?int
    {
        if ($max_attempts > 0 && $failed_count >= $max_attempts) {
            return null;
        }
        return self::retryDelay($failed_count);
    }

    /**
     * Delay before the next upload after a failure, or null if the file is given up
     *
     * The delay grows with all failures in a row. Whether the file is given up
     * depends on the kind of the failure: a processing failure counts against
     * $max_attempts with the processing failures only, an upload failure with all failures.
     *
     * @param int $failed_count Failures in a row including this one, uploads and processings
     * @param int $processing_failures Processing failures in a row including this one
     * @param int $max_attempts 0 = unlimited
     */
    public static function failureRetryDelay(
        int $failed_count,
        int $processing_failures,
        int $max_attempts,
        bool $is_processing_failure
    ): ?int {
        if ($is_processing_failure) {
            return $max_attempts > 0 && $processing_failures >= $max_attempts ? null : self::retryDelay($failed_count);
        }
        return self::nextRetryDelay($failed_count, $max_attempts);
    }

    /**
     * Whether the RAG rejected an upload for a reason a new upload of the same file does not change
     *
     * Network errors (no HTTP status), server errors and rate limits pass; so do
     * authentication errors, which are caused by the configuration and not by the file.
     */
    public static function isPermanentUploadError(int $http_code): bool
    {
        return $http_code >= 400 && $http_code < 500
            && !in_array($http_code, [401, 403, 408, 425, 429], true);
    }

    /**
     * Interval between two status checks of a chat, in seconds
     *
     * Most files are processed quickly, so the state is queried often right after
     * an upload and less often the longer the processing takes.
     *
     * @param int|null $seconds_since_upload Age of the latest upload that is still processing, null if there is none
     */
    public static function checkInterval(?int $seconds_since_upload): int
    {
        if ($seconds_since_upload === null) {
            return self::CHECK_INTERVAL;
        }
        if ($seconds_since_upload < 60) {
            return 5;
        }
        if ($seconds_since_upload < 600) {
            return 30;
        }
        return self::CHECK_INTERVAL;
    }

    /**
     * Local state for a state reported by the RAG
     */
    public static function mapRemoteStatus(string $remote_status): string
    {
        switch (strtoupper($remote_status)) {
            case AIChatPageComponentRAG::STATUS_COMPLETED:
                return self::COMPLETED;
            case AIChatPageComponentRAG::STATUS_FAILED:
                return self::FAILED;
            default:
                return self::PROCESSING;
        }
    }

    /**
     * Final state of a file the RAG reports as completed
     *
     * The chunk count of the RAG decides if it reports one. Otherwise a file with
     * text is completed. A file without text (a scan) is usable if the text
     * recognition of the RAG read it, which only the retrieval shows: it is empty if
     * the probe found nothing, and marked as a scan otherwise.
     *
     * @param int|null $chunks Chunk count reported by the RAG, null if unknown
     * @param bool|null $has_text Whether the file contains text, null if unknown
     * @param bool|null $probe Result of AIChatPageComponentRAG::probeFile(), null if undecided or not asked
     */
    public static function completedState(?int $chunks, ?bool $has_text, ?bool $probe = null): string
    {
        if ($chunks !== null) {
            return $chunks > 0 ? self::COMPLETED : self::EMPTY;
        }
        if ($has_text !== false) {
            return self::COMPLETED;
        }

        return $probe === false ? self::EMPTY : self::SCANNED;
    }

    /**
     * Whether a file contains text the RAG can index
     *
     * PDFs: text needs a font, so a PDF without any font resource consists of images
     * only (e.g. a scan without OCR). Fonts may be stored in compressed object streams.
     *
     * @return bool|null Null if it cannot be decided (other file types, encrypted or unreadable PDFs)
     */
    public static function hasText(string $content, string $suffix): ?bool
    {
        $suffix = strtolower($suffix);
        if (in_array($suffix, ['txt', 'csv', 'md'], true)) {
            return trim($content) !== '';
        }
        if ($suffix !== 'pdf' || strncmp($content, '%PDF-', 5) !== 0) {
            return null;
        }
        if (strpos($content, '/Font') !== false) {
            return true;
        }
        if (strpos($content, '/Encrypt') !== false) {
            return null;
        }

        // Not found in plain objects: search the compressed object streams
        $undecided = false;
        $offset = 0;
        while (($position = strpos($content, '/ObjStm', $offset)) !== false) {
            $offset = $position + 7;
            $start = strpos($content, 'stream', $offset);
            $end = $start === false ? false : strpos($content, 'endstream', $start);
            if ($start === false || $end === false) {
                break;
            }
            $offset = $end;

            $compressed = ltrim(substr($content, $start + 6, $end - $start - 6), "\r\n");
            $data = @gzuncompress($compressed, 16 * 1024 * 1024);
            if ($data === false) {
                $undecided = true;
            } elseif (strpos($data, '/Font') !== false) {
                return true;
            }
        }

        return $undecided ? null : false;
    }

    /**
     * Whether the stored file of an attachment contains text, see hasText()
     */
    private static function attachmentHasText(int $attachment_id): ?bool
    {
        try {
            global $DIC;
            $irss = $DIC->resourceStorage();
            $attachment = \ILIAS\Plugin\pcaic\Model\Attachment::loadById($attachment_id);
            $identification = $attachment !== null ? $attachment->getResourceIdentification() : null;
            if ($identification === null) {
                return null;
            }
            $suffix = (string) $irss->manage()->getCurrentRevision($identification)->getInformation()->getSuffix();
            if (!in_array(strtolower($suffix), ['pdf', 'txt', 'csv', 'md'], true)) {
                return null;
            }
            return self::hasText((string) $irss->consume()->stream($identification)->getStream()->getContents(), $suffix);
        } catch (\Throwable $e) {
            self::logger()->debug("Text check of attachment $attachment_id failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Title of the file of an attachment, used as search text of the probe
     */
    private static function attachmentTitle(int $attachment_id): string
    {
        try {
            $title = (new \ILIAS\Plugin\pcaic\Model\Attachment($attachment_id))->getTitle();
        } catch (\Throwable $e) {
            $title = '';
        }
        return trim(preg_replace('/\.[A-Za-z0-9]+$/', '', (string) $title)) ?: 'document';
    }

    /**
     * State shown to the user for a stored state
     *
     * A failed file without a time of the next attempt is not uploaded again, see markFailed().
     */
    public static function displayState(?string $status, ?string $retry_at): ?string
    {
        return $status === self::FAILED && ($retry_at === null || $retry_at === '') ? self::REJECTED : $status;
    }

    public static function markUploaded(int $attachment_id): void
    {
        self::db()->manipulate(
            "UPDATE pcaic_attachments SET rag_status = " . self::db()->quote(self::PROCESSING, 'text')
            . ", rag_status_error = NULL, rag_retry_at = NULL WHERE id = " . self::db()->quote($attachment_id, 'integer')
        );
    }

    public static function markSkipped(int $attachment_id): void
    {
        self::db()->manipulate(
            "UPDATE pcaic_attachments SET rag_status = " . self::db()->quote(self::SKIPPED, 'text')
            . " WHERE id = " . self::db()->quote($attachment_id, 'integer')
        );
    }

    /**
     * The upload failed before the file was sent with a message: it is uploaded when the message is sent
     */
    public static function markPending(int $attachment_id): void
    {
        $db = self::db();
        $db->manipulate(
            "UPDATE pcaic_attachments SET rag_status = " . $db->quote(self::PENDING, 'text')
            . ", rag_retry_at = " . $db->quote(date('Y-m-d H:i:s'), 'timestamp')
            . " WHERE id = " . $db->quote($attachment_id, 'integer')
            . " AND (rag_remote_file_id IS NULL OR rag_remote_file_id = '')"
        );
    }

    /**
     * Reserve a file for the upload by this request
     *
     * Chat requests and the cron job upload the same files. Only one of several
     * concurrent requests gets the file; without that the file would be stored in
     * the RAG twice and one of the copies would never be deleted. The reservation
     * ends with markUploaded() or markFailed(), or by itself after UPLOAD_CLAIM_TIME
     * if the request died.
     *
     * @return bool False if the file is not due or another request is uploading it
     */
    public static function claimUpload(int $attachment_id): bool
    {
        $db = self::db();
        return $db->manipulate(
            "UPDATE pcaic_attachments SET rag_status = " . $db->quote(self::PENDING, 'text')
            . ", rag_retry_at = " . $db->quote(date('Y-m-d H:i:s', time() + self::UPLOAD_CLAIM_TIME), 'timestamp')
            . " WHERE id = " . $db->quote($attachment_id, 'integer')
            . " AND (rag_remote_file_id IS NULL OR rag_remote_file_id = '')"
            . " AND " . self::uploadDueCondition()
        ) > 0;
    }

    /**
     * Whether an attachment still exists, e.g. after a long upload during which it may have been deleted
     */
    public static function attachmentExists(int $attachment_id): bool
    {
        $db = self::db();
        return $db->fetchAssoc($db->query(
            "SELECT id FROM pcaic_attachments WHERE id = " . $db->quote($attachment_id, 'integer')
        )) !== null;
    }

    /**
     * Upload or processing failed: remove the RAG references and schedule a new upload
     *
     * Upload failures and processing failures are counted separately. Both delay the
     * next upload (rag_failed_count), but only processing failures use up the attempts
     * of MAX_PROCESSING_ATTEMPTS (rag_processing_failures): a file is not given up
     * because the RAG was not reachable for a while before.
     *
     * @param int $max_attempts Upload failures: 0 = unlimited, 1 = no new upload (the RAG rejected the file).
     *                          Processing failures: number of processing failures in a row after which the
     *                          file is given up
     * @param string|null $remote_file_id Processing failures: file in the RAG the failure was reported for. The
     *                          attachment is left alone if it does not refer to this file any more, because
     *                          another request recorded the failure or uploaded the file again in the meantime
     * @return bool False if the attachment was changed by another request and left alone
     */
    public static function markFailed(int $attachment_id, string $error, int $max_attempts = 0, ?string $remote_file_id = null): bool
    {
        $db = self::db();
        $result = $db->query(
            "SELECT rag_failed_count, rag_processing_failures FROM pcaic_attachments WHERE id = " . $db->quote($attachment_id, 'integer')
        );
        $row = $db->fetchAssoc($result);
        $previous_count = (int) ($row['rag_failed_count'] ?? 0);
        $failed_count = $previous_count + 1;
        $is_processing_failure = $remote_file_id !== null;
        $processing_failures = (int) ($row['rag_processing_failures'] ?? 0) + ($is_processing_failure ? 1 : 0);
        $delay = self::failureRetryDelay($failed_count, $processing_failures, $max_attempts, $is_processing_failure);
        // Without a time of the next attempt the file is not uploaded again
        $retry_at = $delay === null ? null : date('Y-m-d H:i:s', time() + $delay);

        $condition = '';
        if ($remote_file_id !== null) {
            // The count is part of the condition: a failure is counted once, also if two requests see it
            $condition = " AND rag_status = " . $db->quote(self::PROCESSING, 'text')
                . " AND rag_remote_file_id = " . $db->quote($remote_file_id, 'text')
                . " AND rag_failed_count = " . $db->quote($previous_count, 'integer');
        }

        return $db->manipulate(
            "UPDATE pcaic_attachments SET"
            . " rag_collection_id = NULL, rag_remote_file_id = NULL, rag_uploaded_at = NULL,"
            . " rag_status = " . $db->quote(self::FAILED, 'text') . ","
            . " rag_status_error = " . $db->quote(mb_substr($error, 0, 1000), 'text') . ","
            . " rag_failed_count = " . $db->quote($failed_count, 'integer') . ","
            . " rag_processing_failures = " . $db->quote($processing_failures, 'integer') . ","
            . " rag_retry_at = " . $db->quote($retry_at, 'timestamp')
            . " WHERE id = " . $db->quote($attachment_id, 'integer') . $condition
        ) > 0 || $remote_file_id === null;
    }

    /**
     * SQL condition for attachments that may be uploaded to the RAG now
     */
    public static function uploadDueCondition(): string
    {
        return "(rag_status IS NULL OR " . self::retryDueCondition() . ")";
    }

    /**
     * SQL condition for attachments whose upload failed or was not finished, and whose new upload is due
     */
    public static function retryDueCondition(): string
    {
        $db = self::db();
        return "(rag_status IN (" . $db->quote(self::FAILED, 'text') . ", " . $db->quote(self::PENDING, 'text') . ")"
            . " AND rag_retry_at IS NOT NULL AND rag_retry_at <= " . $db->quote(date('Y-m-d H:i:s'), 'timestamp') . ")";
    }

    /**
     * Whether background files of the chat are not (yet) usable in the RAG
     */
    public static function hasUnprocessedBackgroundFiles(string $chat_id): bool
    {
        $db = self::db();
        $result = $db->query(
            "SELECT COUNT(*) cnt FROM pcaic_attachments WHERE chat_id = " . $db->quote($chat_id, 'text')
            . " AND background_file = 1 AND (rag_status IS NULL OR rag_status IN ("
            . $db->quote(self::PROCESSING, 'text') . ", " . $db->quote(self::FAILED, 'text') . ", "
            . $db->quote(self::PENDING, 'text') . "))"
        );
        $row = $db->fetchAssoc($result);
        return (int) ($row['cnt'] ?? 0) > 0;
    }

    /**
     * Query the RAG for files of the chat that are still processing, not more often
     * than checkInterval() allows
     *
     * A check may take longer than the interval if the RAG answers slowly, so two
     * requests can query the same file. The result is therefore only stored if the
     * attachment still refers to the queried file and is still processing. The files
     * are queried in random order and only within REFRESH_TIME_BUDGET: a slow RAG
     * neither blocks the request for minutes nor keeps the same files from being checked.
     * The budget also limits the waiting time of each request to the RAG.
     *
     * @param bool $process_deletions Also retry pending deletions (the cron job does that separately)
     * @return bool False if the check was skipped because of the interval
     */
    public static function refreshChat(string $chat_id, bool $process_deletions = true): bool
    {
        $db = self::db();
        $latest = $db->fetchAssoc($db->query(
            "SELECT MAX(rag_uploaded_at) latest FROM pcaic_attachments WHERE chat_id = " . $db->quote($chat_id, 'text')
            . " AND rag_status = " . $db->quote(self::PROCESSING, 'text') . " AND rag_remote_file_id IS NOT NULL"
        ));
        $uploaded_at = empty($latest['latest']) ? false : strtotime((string) $latest['latest']);

        if (!self::claim(
            "UPDATE pcaic_chats SET rag_status_checked_at = %s WHERE chat_id = " . $db->quote($chat_id, 'text')
            . " AND (rag_status_checked_at IS NULL OR rag_status_checked_at <= %s)",
            self::checkInterval($uploaded_at === false ? null : max(0, time() - $uploaded_at))
        )) {
            return false;
        }

        $rag = new AIChatPageComponentRAG();
        $result = $db->query(
            "SELECT a.id, a.rag_remote_file_id, a.rag_collection_id, a.background_file, a.chat_id, a.user_id, m.session_id"
            . " FROM pcaic_attachments a LEFT JOIN pcaic_messages m ON m.message_id = a.message_id"
            . " WHERE a.chat_id = " . $db->quote($chat_id, 'text')
            . " AND a.rag_status = " . $db->quote(self::PROCESSING, 'text')
            . " AND a.rag_remote_file_id IS NOT NULL"
        );

        // Read completely before further queries are sent
        $rows = [];
        while ($row = $db->fetchAssoc($result)) {
            $rows[] = $row;
        }
        shuffle($rows);
        $deadline = time() + self::REFRESH_TIME_BUDGET;

        foreach ($rows as $row) {
            if (time() >= $deadline) {
                self::logger()->debug("RAG status check of chat $chat_id stopped after " . self::REFRESH_TIME_BUDGET . " seconds");
                break;
            }
            // Only if the attachment still is in the state the RAG is asked about
            $unchanged = " WHERE id = " . $db->quote((int) $row['id'], 'integer')
                . " AND rag_status = " . $db->quote(self::PROCESSING, 'text')
                . " AND rag_remote_file_id = " . $db->quote((string) $row['rag_remote_file_id'], 'text');

            // Entity as used for the upload: chat for background files, session for chat uploads
            $entity_id = (int) $row['background_file'] === 1 ? (string) $row['chat_id'] : (string) $row['session_id'];
            if ($entity_id === '') {
                // Chat upload that is not sent with a message yet: uploaded for the session of the user
                $session = \ILIAS\Plugin\pcaic\Model\ChatSession::findForUserAndChat((int) $row['user_id'], $chat_id);
                if ($session === null) {
                    continue;
                }
                $entity_id = $session->getSessionId();
            }
            $status = $rag->getFileStatus((string) $row['rag_remote_file_id'], $entity_id, min(30, $deadline - time()));
            if ($status === null) {
                continue;
            }

            switch (self::mapRemoteStatus($status['status'])) {
                case self::COMPLETED:
                    $chunks = $status['chunks'] ?? null;
                    $has_text = $chunks === null ? self::attachmentHasText((int) $row['id']) : null;
                    // A scan is only searchable if the text recognition of the RAG read it: ask the retrieval once
                    $needs_probe = $chunks === null && $has_text === false;
                    if ($needs_probe && time() >= $deadline) {
                        // Stays processing, the next check asks the retrieval
                        break;
                    }
                    $probe = $needs_probe
                        ? $rag->probeFile(
                            (string) $row['rag_remote_file_id'],
                            (string) ($row['rag_collection_id'] ?? ''),
                            self::attachmentTitle((int) $row['id']),
                            min(30, $deadline - time())
                        )
                        : null;
                    $state = self::completedState($chunks, $has_text, $probe);
                    $stored = $db->manipulate(
                        "UPDATE pcaic_attachments SET rag_status = " . $db->quote($state, 'text')
                        . ", rag_status_error = NULL, rag_failed_count = 0, rag_processing_failures = 0, rag_retry_at = NULL"
                        . $unchanged
                    ) > 0;
                    if ($stored && $state !== self::COMPLETED) {
                        self::logger()->warning("RAG processed attachment " . $row['id'] . " as " . $state
                            . ($state === self::EMPTY ? ": no searchable content" : ": scan, content depends on text recognition"));
                    }
                    break;
                case self::FAILED:
                    if (self::markFailed(
                        (int) $row['id'],
                        (string) ($status['error'] ?? 'Processing failed'),
                        self::MAX_PROCESSING_ATTEMPTS,
                        (string) $row['rag_remote_file_id']
                    )) {
                        self::logger()->warning("RAG processing of attachment " . $row['id'] . " failed: " . ($status['error'] ?? ''));
                    }
                    break;
            }
        }

        // Within the same budget: the request waits for the RAG at most REFRESH_TIME_BUDGET seconds
        if ($process_deletions && time() < $deadline) {
            self::processDeletionQueue($deadline - time());
        }
        return true;
    }

    /**
     * Processing states of the files a user uploaded in the chat
     *
     * @return array<int, string> Attachment ID => state; files that are not handled by the RAG are omitted
     */
    public static function getChatUploadStates(string $chat_id, int $user_id): array
    {
        $db = self::db();
        $result = $db->query(
            "SELECT id, rag_status, rag_retry_at FROM pcaic_attachments WHERE chat_id = " . $db->quote($chat_id, 'text')
            . " AND user_id = " . $db->quote($user_id, 'integer')
            . " AND background_file = 0 AND rag_status IS NOT NULL"
            . " AND rag_status <> " . $db->quote(self::SKIPPED, 'text')
        );

        $states = [];
        while ($row = $db->fetchAssoc($result)) {
            $states[(int) $row['id']] = (string) self::displayState((string) $row['rag_status'], $row['rag_retry_at'] ?? null);
        }
        return $states;
    }

    /**
     * Number of chat uploads of all users of the chat by processing state
     *
     * @return array<string, int> State => number of files
     */
    public static function countChatUploadStates(string $chat_id): array
    {
        $db = self::db();
        $result = $db->query(
            "SELECT rag_status, COUNT(*) cnt FROM pcaic_attachments WHERE chat_id = " . $db->quote($chat_id, 'text')
            . " AND background_file = 0 AND rag_status IS NOT NULL"
            . " AND rag_status <> " . $db->quote(self::SKIPPED, 'text')
            . " GROUP BY rag_status"
        );

        $counts = [];
        while ($row = $db->fetchAssoc($result)) {
            $counts[(string) $row['rag_status']] = (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Remember a file the RAG did not delete yet
     */
    /**
     * Move files into or out of the RAG after the file types of the RAG or of ILIAS changed
     *
     * The retrieval searches whole collections and cannot leave out a single file, so a file
     * whose type no longer belongs in the RAG is deleted there. Files that were skipped because
     * of their type and now belong in the RAG are released for the upload. Runs only if the
     * types changed since the last complete run.
     *
     * @param int $deadline No further file is handled after this time (Unix timestamp)
     * @return array{removed: int, released: int}|null Null if the types did not change or the time ran out
     */
    public static function reconcileFileTypes(
        \ILIAS\Plugin\pcaic\Validation\FileTypePolicy $policy,
        int $deadline
    ): ?array {
        $fingerprint = $policy->ragTypesFingerprint();
        if ((string) \platform\AIChatPageComponentConfig::get(self::FILE_TYPES_KEY) === $fingerprint) {
            return null;
        }

        $db = self::db();
        $result = $db->query(
            "SELECT a.id, a.resource_id, a.chat_id, a.user_id, a.background_file, a.message_id,"
            . " a.rag_remote_file_id, a.rag_status, m.session_id"
            . " FROM pcaic_attachments a LEFT JOIN pcaic_messages m ON m.message_id = a.message_id"
            . " WHERE (a.rag_remote_file_id IS NOT NULL AND a.rag_remote_file_id <> '')"
            . " OR a.rag_status = " . $db->quote(self::SKIPPED, 'text')
        );
        $rows = [];
        while ($row = $db->fetchAssoc($result)) {
            $rows[] = $row;
        }

        $stats = ['removed' => 0, 'released' => 0];
        foreach ($rows as $row) {
            if (time() >= $deadline) {
                // Continued by the next run: the fingerprint is stored only after a complete run
                return null;
            }

            $suffix = \ILIAS\Plugin\pcaic\Model\Attachment::suffixOfResource((string) $row['resource_id']);
            if ($suffix === '') {
                continue;
            }
            $belongs_in_rag = $policy->isRagType($suffix);
            $remote_file_id = (string) ($row['rag_remote_file_id'] ?? '');

            if ($remote_file_id !== '' && !$belongs_in_rag) {
                $entity_id = self::entityOfAttachment($row);
                if ($entity_id === '') {
                    continue;
                }
                self::queueDeletion($remote_file_id, $entity_id);
                $db->manipulate(
                    "UPDATE pcaic_attachments SET rag_collection_id = NULL, rag_remote_file_id = NULL, rag_uploaded_at = NULL,"
                    . " rag_status = " . $db->quote(self::SKIPPED, 'text') . ", rag_status_error = NULL,"
                    . " rag_failed_count = 0, rag_processing_failures = 0, rag_retry_at = NULL"
                    . " WHERE id = " . $db->quote((int) $row['id'], 'integer')
                    . " AND rag_remote_file_id = " . $db->quote($remote_file_id, 'text')
                );
                $stats['removed']++;
            } elseif ($remote_file_id === '' && $row['rag_status'] === self::SKIPPED && $belongs_in_rag) {
                // Background files and unsent chat uploads are uploaded when their status is empty;
                // sent chat uploads are picked up by the cron job as due retry
                $sent_chat_upload = (int) $row['background_file'] === 0 && !empty($row['message_id']);
                $db->manipulate(
                    "UPDATE pcaic_attachments SET"
                    . " rag_status = " . ($sent_chat_upload ? $db->quote(self::PENDING, 'text') : "NULL") . ","
                    . " rag_retry_at = " . ($sent_chat_upload ? $db->quote(date('Y-m-d H:i:s'), 'timestamp') : "NULL")
                    . " WHERE id = " . $db->quote((int) $row['id'], 'integer')
                    . " AND rag_status = " . $db->quote(self::SKIPPED, 'text')
                );
                $stats['released']++;
            }
        }

        \platform\AIChatPageComponentConfig::set(self::FILE_TYPES_KEY, $fingerprint);
        if ($stats['removed'] + $stats['released'] > 0) {
            self::logger()->info("RAG file types changed: " . $stats['removed'] . " files removed from the RAG, "
                . $stats['released'] . " files released for the upload");
        }
        return $stats;
    }

    /**
     * Entity a file was uploaded to the RAG with: the chat for background files, the session for chat uploads
     *
     * @param array $row Attachment row with background_file, chat_id, user_id and session_id
     */
    private static function entityOfAttachment(array $row): string
    {
        if ((int) $row['background_file'] === 1) {
            return (string) $row['chat_id'];
        }
        if (!empty($row['session_id'])) {
            return (string) $row['session_id'];
        }
        // Chat upload not sent yet: uploaded for the session of the user
        $session = \ILIAS\Plugin\pcaic\Model\ChatSession::findForUserAndChat((int) $row['user_id'], (string) $row['chat_id']);
        return $session !== null ? (string) $session->getSessionId() : '';
    }

    public static function queueDeletion(string $remote_file_id, string $entity_id): void
    {
        $db = self::db();
        $result = $db->query("SELECT id FROM pcaic_rag_deletions WHERE remote_file_id = " . $db->quote($remote_file_id, 'text'));
        if ($db->fetchAssoc($result) !== null) {
            return;
        }

        $db->insert('pcaic_rag_deletions', [
            'id' => ['integer', $db->nextId('pcaic_rag_deletions')],
            'remote_file_id' => ['text', $remote_file_id],
            'entity_id' => ['text', $entity_id],
            'attempts' => ['integer', 0],
            'next_try' => ['timestamp', date('Y-m-d H:i:s', time() + self::CHECK_INTERVAL)],
            'created_at' => ['timestamp', date('Y-m-d H:i:s')],
        ]);
        self::logger()->info("RAG deletion of $remote_file_id postponed until processing has finished");
    }

    /**
     * Retry pending deletions, at most once per minute for the whole installation
     *
     * @param int $time_budget No further deletion is started after this number of seconds, and no request to
     *                         the RAG waits longer than the rest of it; 0 = no limit
     */
    public static function processDeletionQueue(int $time_budget = 0): void
    {
        $deadline = $time_budget > 0 ? time() + $time_budget : null;
        $db = self::db();
        $result = $db->query("SELECT config_key FROM pcaic_config WHERE config_key = " . $db->quote(self::DELETION_CHECK_KEY, 'text'));
        if ($db->fetchAssoc($result) === null) {
            \platform\AIChatPageComponentConfig::set(self::DELETION_CHECK_KEY, '1970-01-01 00:00:00');
        }

        if (!self::claim(
            "UPDATE pcaic_config SET config_value = %s WHERE config_key = " . $db->quote(self::DELETION_CHECK_KEY, 'text')
            . " AND config_value <= %s"
        )) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $db->setLimit(self::DELETIONS_PER_RUN, 0);
        $result = $db->query(
            "SELECT id, remote_file_id, entity_id, attempts FROM pcaic_rag_deletions"
            . " WHERE next_try IS NULL OR next_try <= " . $db->quote($now, 'timestamp') . " ORDER BY next_try"
        );

        // Read completely before further queries are sent
        $rows = [];
        while ($row = $db->fetchAssoc($result)) {
            $rows[] = $row;
        }

        $rag = new AIChatPageComponentRAG();
        foreach ($rows as $row) {
            if ($deadline !== null && time() >= $deadline) {
                // The rest is due again with the next run
                break;
            }
            $attempts = (int) $row['attempts'] + 1;
            $deleted = false;
            try {
                $deleted = $rag->deleteFile(
                    (string) $row['remote_file_id'],
                    (string) $row['entity_id'],
                    $deadline === null ? 60 : min(60, $deadline - time())
                );
            } catch (\Exception $e) {
                self::logger()->warning("Pending RAG deletion failed: " . $e->getMessage());
            }

            if ($deleted || $attempts >= self::MAX_DELETION_ATTEMPTS) {
                if (!$deleted) {
                    self::logger()->error("RAG deletion of " . $row['remote_file_id'] . " given up after $attempts attempts");
                }
                $db->manipulate("DELETE FROM pcaic_rag_deletions WHERE id = " . $db->quote((int) $row['id'], 'integer'));
                continue;
            }

            $db->manipulate(
                "UPDATE pcaic_rag_deletions SET attempts = " . $db->quote($attempts, 'integer')
                . ", next_try = " . $db->quote(date('Y-m-d H:i:s', time() + self::retryDelay(
                    $attempts,
                    self::DELETION_RETRY_BASE_DELAY,
                    self::DELETION_RETRY_MAX_DELAY
                )), 'timestamp')
                . " WHERE id = " . $db->quote((int) $row['id'], 'integer')
            );
        }
    }

    /**
     * Execute a conditional UPDATE that sets a timestamp; only one of several
     * concurrent requests changes the row and gets the claim
     *
     * @param string $query UPDATE with two %s placeholders: new timestamp, latest allowed old timestamp
     * @param int $interval Minimum age of the old timestamp, in seconds
     */
    private static function claim(string $query, int $interval = self::CHECK_INTERVAL): bool
    {
        $db = self::db();
        $now = date('Y-m-d H:i:s');
        $threshold = date('Y-m-d H:i:s', time() - $interval);
        return $db->manipulate(sprintf($query, $db->quote($now, 'text'), $db->quote($threshold, 'text'))) > 0;
    }

    private static function db(): \ilDBInterface
    {
        global $DIC;
        return $DIC->database();
    }

    private static function logger(): \ilLogger
    {
        global $DIC;
        return $DIC->logger()->pcaic();
    }
}
