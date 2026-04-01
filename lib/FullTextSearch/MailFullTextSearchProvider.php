<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\FullTextSearch;

use OC\FullTextSearch\Model\DocumentAccess;
use OC\FullTextSearch\Model\IndexDocument;
use OC\FullTextSearch\Model\SearchTemplate;
use OCA\Mail\Account;
use OCA\Mail\Db\MailboxMapper;
use OCA\Mail\Db\MessageMapper;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\IMAP\MessageMapper as ImapMessageMapper;
use OCA\Mail\Service\AccountService;
use OCP\FullTextSearch\IFullTextSearchPlatform;
use OCP\FullTextSearch\IFullTextSearchProvider;
use OCP\IURLGenerator;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IIndexOptions;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\FullTextSearch\Model\ISearchTemplate;
use Psr\Log\LoggerInterface;

/**
 * FullTextSearch content provider for the Mail app.
 *
 * Indexes message subjects, bodies, sender/recipient addresses and
 * attachment content so that the FTS platform (e.g. fulltextsearch_elasticsearch)
 * can search across all of them.
 *
 * Registered in appinfo/info.xml:
 *   <fulltextsearch>
 *     <provider>OCA\Mail\FullTextSearch\MailFullTextSearchProvider</provider>
 *   </fulltextsearch>
 */
class MailFullTextSearchProvider implements IFullTextSearchProvider {
	private const PROVIDER_ID = 'mail';

	private ?IRunner $runner = null;

	public function __construct(
		private AccountService $accountService,
		private MailboxMapper $mailboxMapper,
		private MessageMapper $messageMapper,
		private IMAPClientFactory $clientFactory,
		private ImapMessageMapper $imapMapper,
		private LoggerInterface $logger,
		private IURLGenerator $urlGenerator,
	) {
	}

	// -------------------------------------------------------------------------
	// IFullTextSearchProvider identity
	// -------------------------------------------------------------------------

	public function getId(): string {
		return self::PROVIDER_ID;
	}

	public function getName(): string {
		return 'Mails';
	}

	public function getConfiguration(): array {
		return [];
	}

	public function getSearchTemplate(): ISearchTemplate {
		return new SearchTemplate('icon-mail', '');
	}

	// -------------------------------------------------------------------------
	// Lifecycle hooks
	// -------------------------------------------------------------------------

	public function loadProvider(): void {
	}

	public function unloadProvider(): void {
	}

	public function setRunner(IRunner $runner): void {
		$this->runner = $runner;
	}

	public function setIndexOptions(IIndexOptions $options): void {
	}

	public function onInitializingIndex(IFullTextSearchPlatform $platform): void {
	}

	public function onResettingIndex(IFullTextSearchPlatform $platform): void {
	}

	// -------------------------------------------------------------------------
	// Indexing
	// -------------------------------------------------------------------------

	/**
	 * Returns one chunk token per mailbox for the user.
	 * Each chunk is a mailbox DB ID as a string so the FTS framework can
	 * report per-mailbox progress and parallelize.
	 *
	 * @return string[]
	 */
	public function generateChunks(string $userId): array {
		$chunks = [];
		foreach ($this->accountService->findByUserId($userId) as $account) {
			foreach ($this->mailboxMapper->findAll($account) as $mailbox) {
				$chunks[] = (string)$mailbox->getId();
			}
		}
		return $chunks;
	}

	/**
	 * Returns lightweight document stubs for every message in the given mailbox
	 * chunk. For messages with binary attachments (PDFs, etc.) an additional stub
	 * is created per attachment MIME part so the Elasticsearch ingest pipeline can
	 * extract their text independently without overwriting the email body content.
	 *
	 * A single IMAP connection is opened per mailbox to batch the structure
	 * fetches; only structure (not content) is fetched at this stage.
	 *
	 * @return IIndexDocument[]
	 */
	public function generateIndexableDocuments(string $userId, string $chunk): array {
		try {
			$mailbox = $this->mailboxMapper->findById((int)$chunk);
		} catch (\Throwable $e) {
			$this->logger->warning('MailFullTextSearchProvider: mailbox {chunk} not found: {error}', [
				'chunk' => $chunk, 'error' => $e->getMessage(),
			]);
			return [];
		}

		$ids = $this->messageMapper->findAllIds($mailbox);
		$messages = $this->messageMapper->findByIds($userId, $ids, 'DESC');

		// Map uid → binary attachment mimeId list.
		//
		// We use getBodyStructureData() rather than the DB flag_attachments
		// column because that column can be 0/null even for messages that
		// genuinely have attachments (e.g. when the sync ran before the flag
		// was set, or the IMAP server didn't advertise \HasAttachments).
		// getBodyStructureData() is the same IMAP-based check used in
		// fillMessageDocument(), so the two methods stay in sync.
		$binaryMimeIdsByUid = [];
		if ($messages !== []) {
			try {
				$account = $this->accountService->find(
					$userId,
					$mailbox->getAccountId(),
				);
				$client = $this->clientFactory->getClient($account);
				try {
					$allUids    = array_map(static fn ($m) => $m->getUid(), $messages);
					$structures = $this->imapMapper->getBodyStructureData(
						$client,
						$mailbox->getName(),
						$allUids,
						$account->getEMailAddress(),
					);
					foreach ($messages as $msg) {
						$sd = $structures[$msg->getUid()] ?? null;
						if (!$sd?->hasAttachments()) {
							continue;
						}
						$mimeTypes = $this->imapMapper->getAttachmentMimeTypes(
							$client,
							$mailbox->getName(),
							$msg->getUid(),
						);
						$binaryIds = [];
						foreach ($mimeTypes as $mimeId => $mimeType) {
							if ($this->isBinaryMimeType($mimeType)) {
								$binaryIds[] = $mimeId;
							}
						}
						if ($binaryIds !== []) {
							$binaryMimeIdsByUid[$msg->getUid()] = $binaryIds;
						}
					}
				} finally {
					$client->logout();
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'MailFullTextSearchProvider: could not fetch attachment structure for mailbox {mailbox}: {error}',
					['mailbox' => $mailbox->getName(), 'error' => $e->getMessage()],
				);
			}
		}

		$stubs = [];
		foreach ($messages as $message) {
			$stub = new IndexDocument(self::PROVIDER_ID, (string)$message->getId());
			$stub->setModifiedTime($message->getSentAt());
			$stub->setInfo('owner', $userId);
			$stubs[] = $stub;

			// Add one stub per binary attachment MIME part so the ES ingest
			// pipeline can extract content from each independently.
			$binaryMimeIds = $binaryMimeIdsByUid[$message->getUid()] ?? [];
			foreach ($binaryMimeIds as $mimeId) {
				$attachStub = new IndexDocument(
					self::PROVIDER_ID,
					$message->getId() . ':attachment:' . $mimeId,
				);
				$attachStub->setModifiedTime($message->getSentAt());
				$attachStub->setInfo('owner', $userId);
				$stubs[] = $attachStub;
			}
		}
		return $stubs;
	}

	/**
	 * Returns true for MIME types that extractTextFromAttachment() cannot
	 * handle in-process and that therefore require the Elasticsearch ingest
	 * attachment pipeline for text extraction.
	 */
	private function isBinaryMimeType(string $mimeType): bool {
		$t = strtolower(explode(';', $mimeType)[0]);
		return !str_starts_with($t, 'text/plain')
			&& !str_starts_with($t, 'text/html')
			&& $t !== 'text/calendar'
			&& $t !== 'message/rfc822';
	}

	/**
	 * Returns true if the document's stored index timestamp is at least as
	 * recent as the message's sentAt — meaning nothing changed since last index.
	 */
	public function isDocumentUpToDate(IIndexDocument $document): bool {
		$index = $document->getIndex();
		if ($index === null) {
			return false;
		}
		return $index->getLastIndex() >= $document->getModifiedTime();
	}

	/**
	 * Populates an IndexDocument with full content before the platform indexes it.
	 *
	 * Document ID formats:
	 *   "{msgId}"                    → email body document (fillMessageDocument)
	 *   "{msgId}:attachment:{mimeId}" → binary attachment document (fillAttachmentDocument)
	 */
	public function fillIndexDocument(IIndexDocument $document): void {
		try {
			if (str_contains($document->getId(), ':attachment:')) {
				$this->fillAttachmentDocument($document);
			} else {
				$this->fillMessageDocument($document);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'MailFullTextSearchProvider: failed to fill document {id}: {error}',
				['id' => $document->getId(), 'error' => $e->getMessage()],
			);
		}
	}

	/**
	 * Called by FTS for live/cron updates after a document is queued via
	 * IFullTextSearchManager::createIndex().
	 */
	public function updateDocument(IIndex $index): IIndexDocument {
		$document = new IndexDocument(self::PROVIDER_ID, $index->getDocumentId());
		$document->setIndex($index);
		$this->fillIndexDocument($document);
		return $document;
	}

	// -------------------------------------------------------------------------
	// Search
	// -------------------------------------------------------------------------

	/**
	 * Restricts the search to specific document parts when the user searched
	 * using field-specific tokens (subject:, body:, attachment:, etc.).
	 * When mail_parts is empty the search spans all indexed parts, which is the
	 * correct behaviour for simple / unified search.
	 */
	public function improveSearchRequest(ISearchRequest $searchRequest): void {
		$parts = $searchRequest->getOptionArray('mail_parts');
		foreach ($parts as $part) {
			$searchRequest->addPart($part);
		}
	}

	/**
	 * Sets a clickable link on every result document so the fulltextsearch UI
	 * can navigate the user directly to the email thread.
	 *
	 * Both regular message documents (ID = "{msgId}") and attachment documents
	 * (ID = "{msgId}:attachment:{mimeId}") are linked to the same thread view.
	 *
	 * Note: the Elasticsearch platform's parseSearchEntry() does not populate
	 * info fields on search result documents, so we cannot rely on
	 * getInfoInt('mailbox_id') here. Instead we do a batched DB lookup for all
	 * unique message IDs in the result set.
	 */
	public function improveSearchResult(ISearchResult $searchResult): void {
		$documents = $searchResult->getDocuments();
		if ($documents === []) {
			return;
		}

		// The viewer ID is the same for every document in a single search result.
		$userId = $documents[0]->getAccess()?->getViewerId() ?? '';

		// Collect unique message IDs (both "7" and "7:attachment:2" map to 7).
		$msgIds = [];
		foreach ($documents as $document) {
			$msgId = (int)explode(':', $document->getId())[0];
			if ($msgId > 0) {
				$msgIds[$msgId] = true;
			}
		}

		// Batch-load mailbox IDs from the DB.
		$mailboxIdByMsgId = [];
		foreach (array_keys($msgIds) as $msgId) {
			try {
				$message = $this->messageMapper->findByUserId($userId, $msgId);
				$mailboxIdByMsgId[$msgId] = $message->getMailboxId();
			} catch (\Throwable) {
				// Message may have been deleted; skip it.
			}
		}

		// Set links on all documents.
		foreach ($documents as $document) {
			$msgId     = (int)explode(':', $document->getId())[0];
			$mailboxId = $mailboxIdByMsgId[$msgId] ?? 0;

			if ($msgId <= 0 || $mailboxId <= 0) {
				continue;
			}

			// linkToRoute('mail.page.thread', ...) only resolves when the mail
			// app's routes have been registered in the current request. To avoid
			// a dependency on app-load order, we build the URL directly from the
			// known, stable pattern defined in appinfo/routes.php.
			$link = '/index.php/apps/mail/box/' . $mailboxId . '/thread/' . $msgId;
			$document->setLink($link);
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Fills a message document: fetches the full body via IMAP, extracts plain
	 * text (with HTML fallback), indexes subject/from/to/cc/bcc as separate
	 * parts, tags attachment filenames, and extracts text from text-based
	 * attachments inline.
	 */
	private function fillMessageDocument(IIndexDocument $document): void {
		$userId  = $document->getInfo('owner') ?: ($document->getIndex()?->getOwnerId() ?? '');
		$msgId   = (int)$document->getId();

		$message = $this->messageMapper->findByUserId($userId, $msgId);
		$mailbox = $this->mailboxMapper->findById($message->getMailboxId());
		$account = $this->accountService->find($userId, $mailbox->getAccountId());

		// ---- Title and address parts ----------------------------------------
		$subject = $message->getSubject() ?? '';
		$document->setTitle($subject);
		$document->addPart('subject', $subject);
		$document->addPart('from', $this->addressListToString($message->getFrom()));
		$document->addPart('to',   $this->addressListToString($message->getTo()));
		$document->addPart('cc',   $this->addressListToString($message->getCc()));
		$document->addPart('bcc',  $this->addressListToString($message->getBcc()));

		// ---- Fetch body + attachments via IMAP --------------------------------
		$client = $this->clientFactory->getClient($account);
		try {
			$structures = $this->imapMapper->getBodyStructureData(
				$client,
				$mailbox->getName(),
				[$message->getUid()],
				$account->getEMailAddress(),
			);
			$structureData = $structures[$message->getUid()] ?? null;

			// Full body text — getPreviewText() returns the complete HTML-to-plain
			// conversion; it is NOT truncated at this stage.
			$bodyText = $structureData?->getPreviewText() ?? '';
			$document->setContent($bodyText);

			// ---- Attachments -------------------------------------------------
			if ($structureData?->hasAttachments()) {
				$attachments = $this->imapMapper->getAttachments(
					$client,
					$mailbox->getName(),
					$message->getUid(),
					$userId,
				);
				$attachmentTexts = [];
				foreach ($attachments as $attachment) {
					// Always tag the filename so it is searchable by name
					$name = $attachment->getName();
					if ($name !== null && $name !== '') {
						$document->addTag($name);
					}
					$text = $this->extractTextFromAttachment(
						$attachment->getType(),
						$attachment->getContent(),
						$attachment->getSize(),
					);
					if ($text !== '') {
						$attachmentTexts[] = $text;
					}
				}
				if ($attachmentTexts !== []) {
					$document->addPart('attachments', implode("\n\n", $attachmentTexts));
				}
			}
		} finally {
			$client->logout();
		}

		// ---- Metadata --------------------------------------------------------
		$document->setModifiedTime($message->getSentAt());
		$document->setInfoInt('mailbox_id', $message->getMailboxId());
		$document->setInfoBool('flag_seen',        (bool)$message->getFlagSeen());
		$document->setInfoBool('flag_flagged',      (bool)$message->getFlagFlagged());
		$document->setInfoBool('flag_answered',     (bool)$message->getFlagAnswered());
		$document->setInfoBool('flag_deleted',      (bool)$message->getFlagDeleted());
		$document->setInfoBool('flag_attachments',  (bool)$message->getFlagAttachments());
		$document->setInfoBool('flag_important',    (bool)$message->getFlagImportant());

		// ---- Access ----------------------------------------------------------
		$access = new DocumentAccess();
		$access->setOwnerId($userId);
		$document->setAccess($access);
	}

	/**
	 * Fills a binary-attachment document for the Elasticsearch ingest pipeline.
	 *
	 * Document ID format: "{msgId}:attachment:{mimeId}"
	 *
	 * The attachment's raw bytes are base64-encoded and stored in the document's
	 * content field with the ENCODED_BASE64 flag. This triggers the
	 * fulltextsearch_elasticsearch ingest pipeline, which runs the Elasticsearch
	 * attachment processor (built into ES 8.x) to extract plain text from the
	 * binary content server-side — no external tools required.
	 *
	 * Email metadata (subject, from, to) is also stored as parts so search
	 * results for attachment content can be displayed in context.
	 */
	private function fillAttachmentDocument(IIndexDocument $document): void {
		[$msgId, , $mimeId] = explode(':', $document->getId(), 3);
		$msgId = (int)$msgId;

		$userId  = $document->getInfo('owner') ?: ($document->getIndex()?->getOwnerId() ?? '');
		$message = $this->messageMapper->findByUserId($userId, $msgId);
		$mailbox = $this->mailboxMapper->findById($message->getMailboxId());
		$account = $this->accountService->find($userId, $mailbox->getAccountId());

		// ---- Email metadata for search-result context -----------------------
		$subject = $message->getSubject() ?? '';
		$document->setTitle($subject);
		$document->addPart('subject', $subject);
		$document->addPart('from', $this->addressListToString($message->getFrom()));
		$document->addPart('to',   $this->addressListToString($message->getTo()));
		$document->setModifiedTime($message->getSentAt());
		$document->setInfoInt('mailbox_id', $message->getMailboxId());

		// ---- Access ---------------------------------------------------------
		$access = new DocumentAccess();
		$access->setOwnerId($userId);
		$document->setAccess($access);

		// ---- Fetch only the specific MIME part ------------------------------
		$client = $this->clientFactory->getClient($account);
		try {
			$attachments = $this->imapMapper->getAttachments(
				$client,
				$mailbox->getName(),
				$message->getUid(),
				$userId,
				[$mimeId],
			);
		} finally {
			$client->logout();
		}

		if ($attachments === []) {
			return;
		}

		$attachment = $attachments[0];

		// Tag the filename so it is searchable by name
		$name = $attachment->getName();
		if ($name !== null && $name !== '') {
			$document->addTag($name);
		}

		// Store base64-encoded binary as document content. The
		// fulltextsearch_elasticsearch ingest pipeline detects ENCODED_BASE64
		// and routes the document through the ES attachment processor, which
		// replaces the binary with the extracted plain text before indexing.
		$document->setContent(
			base64_encode($attachment->getContent()),
			IIndexDocument::ENCODED_BASE64,
		);
	}

	/**
	 * Extracts plain text from an attachment based on its MIME type.
	 *
	 * Binary types (PDFs, Office docs, images, etc.) return '' — their content
	 * is indexed via separate attachment documents using the Elasticsearch ingest
	 * pipeline (see fillAttachmentDocument). Their filenames remain searchable
	 * through the tags added by fillMessageDocument().
	 *
	 * A 10 MB size guard prevents memory exhaustion on large attachments.
	 */
	private function extractTextFromAttachment(string $mimeType, string $content, int $size): string {
		if ($size > 10_000_000) {
			return '';
		}

		$type = strtolower(explode(';', $mimeType)[0]);

		return match (true) {
			str_starts_with($type, 'text/plain')
				=> $content,
			str_starts_with($type, 'text/html')
				=> trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
			$type === 'text/calendar'
				=> $content,
			$type === 'message/rfc822'
				=> trim(strip_tags($content)),
			default
				=> '',
		};
	}

	/**
	 * Joins all email addresses and display names from an AddressList into a
	 * single space-separated string suitable for full-text indexing.
	 */
	private function addressListToString(\OCA\Mail\AddressList $list): string {
		$parts = [];
		foreach ($list->iterate() as $address) {
			if ($address->getEmail() !== null) {
				$parts[] = $address->getEmail();
			}
			if ($address->getLabel() !== null) {
				$parts[] = $address->getLabel();
			}
		}
		return implode(' ', $parts);
	}
}
