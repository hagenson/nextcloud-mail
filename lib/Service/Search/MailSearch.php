<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\Search;

use Horde_Imap_Client;
use OCA\Mail\Account;
use OCA\Mail\Contracts\IMailSearch;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\Message;
use OCA\Mail\Db\MessageMapper;
use OCA\Mail\Exception\ClientException;
use OCA\Mail\Exception\MailboxLockedException;
use OCA\Mail\Exception\MailboxNotCachedException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\IMAP\PreviewEnhancer;
use OCA\Mail\IMAP\Search\Provider as ImapSearchProvider;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\IUser;

class MailSearch implements IMailSearch {
	/** @var FilterStringParser */
	private $filterStringParser;

	/** @var ImapSearchProvider */
	private $imapSearchProvider;

	/** @var MessageMapper */
	private $messageMapper;

	/** @var PreviewEnhancer */
	private $previewEnhancer;

	/** @var ITimeFactory */
	private $timeFactory;

	/** @var IFullTextSearchManager */
	private $ftsManager;

	public function __construct(FilterStringParser $filterStringParser,
		ImapSearchProvider $imapSearchProvider,
		MessageMapper $messageMapper,
		PreviewEnhancer $previewEnhancer,
		ITimeFactory $timeFactory,
		IFullTextSearchManager $ftsManager) {
		$this->filterStringParser = $filterStringParser;
		$this->imapSearchProvider = $imapSearchProvider;
		$this->messageMapper = $messageMapper;
		$this->previewEnhancer = $previewEnhancer;
		$this->timeFactory = $timeFactory;
		$this->ftsManager = $ftsManager;
	}

	#[\Override]
	public function findMessage(Account $account,
		Mailbox $mailbox,
		Message $message): Message {
		$processed = $this->previewEnhancer->process(
			$account,
			$mailbox,
			[$message]
		);
		if ($processed === []) {
			throw new DoesNotExistException('Message does not exist');
		}
		return $processed[0];
	}

	/**
	 * @param Account $account
	 * @param Mailbox $mailbox
	 * @param string $sortOrder
	 * @param string|null $filter
	 * @param int|null $cursor
	 * @param int|null $limit
	 * @param string|null $view
	 *
	 * @return Message[]
	 *
	 * @throws ClientException
	 * @throws ServiceException
	 */
	#[\Override]
	public function findMessages(Account $account,
		Mailbox $mailbox,
		string $sortOrder,
		?string $filter,
		?int $cursor,
		?int $limit,
		?string $userId,
		?string $view): array {
		if ($mailbox->hasLocks($this->timeFactory->getTime())) {
			throw MailboxLockedException::from($mailbox);
		}
		if (!$mailbox->isCached()) {
			throw MailboxNotCachedException::from($mailbox);
		}

		$query = $this->filterStringParser->parse($filter);
		if ($cursor !== null) {
			$query->setCursor($cursor);
		}
		if ($view !== null) {
			$query->setThreaded($view === self::VIEW_THREADED);
		}
		// In flagged we don't want anything but flagged messages
		if ($mailbox->isSpecialUse(Horde_Imap_Client::SPECIALUSE_FLAGGED)) {
			$query->addFlag(Flag::is(Flag::FLAGGED));
		}
		// Don't show deleted messages except for trash folders
		if (!$mailbox->isSpecialUse(Horde_Imap_Client::SPECIALUSE_TRASH)) {
			$query->addFlag(Flag::not(Flag::DELETED));
		}

		return $this->previewEnhancer->process(
			$account,
			$mailbox,
			$this->messageMapper->findByIds($account->getUserId(),
				$this->getIdsLocally($account, $mailbox, $query, $sortOrder, $limit),
				$sortOrder,
			),
			true,
			$userId
		);
	}

	/**
	 * Find messages across all mailboxes for a user
	 *
	 * @return Message[]
	 *
	 * @throws ServiceException
	 */
	#[\Override]
	public function findMessagesGlobally(
		IUser $user,
		SearchQuery $query,
		?int $limit): array {
		return $this->messageMapper->findByIds($user->getUID(),
			$this->getIdsGlobally($user, $query, $limit),
			'DESC'
		);
	}

	/**
	 * Routes search to FullTextSearch when available, otherwise falls back to
	 * the original IMAP body search + DB metadata filter path.
	 *
	 * @throws ServiceException
	 */
	private function getIdsLocally(Account $account, Mailbox $mailbox, SearchQuery $query, string $sortOrder, ?int $limit): array {
		if ($this->isFtsReady()) {
			$ids = $this->searchViaFts(
				$account->getUserId(),
				$query,
				$mailbox->getId(),
				$limit,
				$sortOrder,
			);
			// Apply structured filters (flags, date range) as a DB post-filter on
			// the FTS candidate set.
			return $this->messageMapper->findIdsByQuery($mailbox, $query, $sortOrder, $limit, $ids);
		}

		// Original fallback: IMAP body search + DB metadata filter
		if (empty($query->getBodies()) && empty($query->getAttachments())) {
			return $this->messageMapper->findIdsByQuery($mailbox, $query, $sortOrder, $limit);
		}

		$fromImap = $this->imapSearchProvider->findMatches($account, $mailbox, $query);
		return $this->messageMapper->findIdsByQuery($mailbox, $query, $sortOrder, $limit, $fromImap);
	}

	/**
	 * Routes global (cross-mailbox) search to FullTextSearch when available,
	 * otherwise falls back to the original DB-only global query (no body search).
	 *
	 * @throws ServiceException
	 */
	private function getIdsGlobally(IUser $user, SearchQuery $query, ?int $limit): array {
		if ($this->isFtsReady()) {
			$ids = $this->searchViaFts($user->getUID(), $query, null, $limit, 'DESC');
			// null means no text terms — fall through to the DB-only path so that
			// structured filters (flags, dates, etc.) still work globally.
			if ($ids !== null) {
				return $ids;
			}
		}

		// Original fallback: DB metadata search only (body/attachment search not
		// possible cross-mailbox without FTS).
		return $this->messageMapper->findIdsGloballyByQuery($user, $query, $limit);
	}

	/**
	 * Returns true when FullTextSearch is installed, configured and has
	 * indexed at least one mail document.
	 */
	private function isFtsReady(): bool {
		return $this->ftsManager->isAvailable()
			&& $this->ftsManager->isProviderIndexed('mail');
	}

	/**
	 * Executes a search via IFullTextSearchManager and returns matching DB
	 * message IDs.
	 *
	 * The search request array follows the format documented on
	 * ISearchService::generateSearchRequest():
	 *   - 'search'    : combined search string from all text fields
	 *   - 'providers' : restricted to 'mail'
	 *   - 'size'      : maximum results
	 *   - 'options'   : mail_parts (non-empty = field-specific search)
	 *
	 * Returns null when there are no text search terms (caller should apply
	 * structured DB filters without any FTS candidate restriction).
	 * Returns an empty array when FTS was consulted but found no matches.
	 *
	 * @return int[]|null
	 */
	private function searchViaFts(
		string $userId,
		SearchQuery $query,
		?int $mailboxId,
		?int $limit,
		string $sortOrder = 'DESC',
	): ?array {
		// Build a combined search string from all text-oriented query fields.
		$terms = array_merge(
			$query->getBodies(),
			$query->getAttachments(),
			$query->getSubjects(),
			$query->getFrom(),
			$query->getTo(),
			$query->getCc(),
		);
		$searchString = implode(' ', array_unique(array_filter($terms)));
		if ($searchString === '') {
			// No text terms — signal the caller to skip FTS candidate filtering
			// and apply only the structured DB filters (flags, dates, etc.).
			return null;
		}

		// Determine which document parts to restrict the search to.
		// When the user used field-specific tokens (subject:, body:, attachment:)
		// we scope the FTS search accordingly. For a plain / simple search we
		// leave mail_parts empty so all parts (including attachments) are searched.
		$parts = [];
		if (!empty($query->getSubjects()) && empty($query->getBodies()) && empty($query->getAttachments())) {
			$parts = ['subject'];
		} elseif (!empty($query->getBodies()) && empty($query->getSubjects()) && empty($query->getAttachments())) {
			$parts = ['content', 'subject', 'from', 'to', 'cc', 'bcc'];
		} elseif (!empty($query->getAttachments()) && empty($query->getBodies()) && empty($query->getSubjects())) {
			$parts = ['attachments'];
		}
		// Mixed field tokens → leave parts empty to search everything.

		$request = [
			'providers' => ['mail'],
			'search'    => $searchString,
			'size'      => $limit ?? 50,
			'options'   => ['mail_parts' => $parts],
		];

		try {
			$results = $this->ftsManager->search($request, $userId);
		} catch (\Throwable $e) {
			// FTS failure must not break the UI — return empty and let the DB
			// post-filter produce whatever it can from metadata alone.
			return [];
		}

		if (empty($results)) {
			return [];
		}

		/** @var \OCP\FullTextSearch\Model\ISearchResult $result */
		$result = $results[0];
		return array_map(
			static fn ($doc) => (int)$doc->getId(),
			$result->getDocuments(),
		);
	}
}
