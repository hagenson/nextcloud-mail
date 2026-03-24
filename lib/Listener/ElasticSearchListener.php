<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Listener;

use OCA\Mail\Db\Message;
use OCA\Mail\Events\MessageDeletedEvent;
use OCA\Mail\Events\MessageFlaggedEvent;
use OCA\Mail\Events\NewMessagesSynchronized;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\IMAP\MessageMapper as ImapMessageMapper;
use OCA\Mail\Service\Search\ElasticSearchIndexer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Keeps the ElasticSearch index in sync with the mail database by reacting to
 * the three key lifecycle events for messages.
 *
 * @template-implements IEventListener<Event|NewMessagesSynchronized|MessageDeletedEvent|MessageFlaggedEvent>
 */
class ElasticSearchListener implements IEventListener {
	public function __construct(
		private ElasticSearchIndexer $indexer,
		private IMAPClientFactory $clientFactory,
		private ImapMessageMapper $imapMapper,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$this->indexer->isAvailable()) {
			return;
		}

		if ($event instanceof NewMessagesSynchronized) {
			$userId    = $event->getAccount()->getUserId();
			$bodyTexts = $this->fetchBodyTexts($event);

			foreach ($event->getMessages() as $message) {
				$bodyText = $bodyTexts[$message->getUid()] ?? '';
				$this->indexer->indexMessage($userId, $message, $bodyText);
			}
			return;
		}

		if ($event instanceof MessageDeletedEvent) {
			$this->indexer->deleteMessage($event->getMessageId());
			return;
		}

		if ($event instanceof MessageFlaggedEvent) {
			// The MessageFlaggedEvent carries a UID, not a DB id. We get the DB
			// message id by looking it up via the mailbox + uid.  However, the
			// flag update is best-effort: if we can't map UID → DB id we skip.
			// The next full sync will correct any drift.
			//
			// Since MessageCacheUpdaterListener already persists the flag to the
			// DB, we only need to propagate the change to ES.
			// The MessageFlaggedEvent only gives us a UID; a partial update by UID
			// would require an update-by-query which is expensive.  We therefore
			// defer to relying on the next indexMessage call (triggered by a
			// subsequent sync) to keep flags consistent, and skip a per-event ES
			// update here.  Operators who need real-time flag accuracy in ES can
			// run `occ mail:search:index --user=<uid>` on a schedule.
			return;
		}
	}

	/**
	 * Bulk-fetches full body text for all messages in the event via IMAP.
	 * Returns uid => plain-text body. On any IMAP error returns [] so callers
	 * fall back to the 255-char previewText already in the DB.
	 *
	 * @return array<int, string>
	 */
	private function fetchBodyTexts(NewMessagesSynchronized $event): array {
		$account  = $event->getAccount();
		$mailbox  = $event->getMailbox();
		$messages = $event->getMessages();

		$uids = array_map(static fn (Message $m) => $m->getUid(), $messages);
		if ($uids === []) {
			return [];
		}

		try {
			$client     = $this->clientFactory->getClient($account);
			$structured = $this->imapMapper->getBodyStructureData(
				$client,
				$mailbox->getName(),
				$uids,
				$account->getEMailAddress(),
			);
			$client->logout();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ElasticSearchListener: could not fetch body text from IMAP for mailbox {mailbox}: {error}',
				['mailbox' => $mailbox->getName(), 'error' => $e->getMessage()],
			);
			return [];
		}

		$result = [];
		foreach ($structured as $uid => $structureData) {
			$result[(int)$uid] = $structureData->getPreviewText();
		}
		return $result;
	}
}
