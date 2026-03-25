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
	 * chunk. No body or attachment bytes are fetched here — that happens later
	 * in fillIndexDocument().
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

		$stubs = [];
		foreach ($messages as $message) {
			$stub = new IndexDocument(self::PROVIDER_ID, (string)$message->getId());
			$stub->setModifiedTime($message->getSentAt());
			$stubs[] = $stub;
		}
		return $stubs;
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
	 * Routes to the appropriate fill method based on document ID format.
	 */
	public function fillIndexDocument(IIndexDocument $document): void {
		try {
			$this->fillMessageDocument($document);
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
	 * No enrichment needed — document IDs are already DB message IDs.
	 */
	public function improveSearchResult(ISearchResult $searchResult): void {
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
		$userId  = $document->getIndex()?->getOwnerId() ?? '';
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
	 * Extracts plain text from an attachment based on its MIME type.
	 * Binary types (PDF, Office, etc.) return '' because they cannot be
	 * decoded in PHP without external tools. Their filenames are still
	 * searchable via the tags added by fillMessageDocument().
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
