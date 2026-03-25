<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Listener;

use OCA\Mail\Events\MessageDeletedEvent;
use OCA\Mail\Events\MessageFlaggedEvent;
use OCA\Mail\Events\NewMessagesSynchronized;
use OCA\Mail\Db\Message;
use OCA\Mail\Db\MessageMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\IIndex;
use Psr\Log\LoggerInterface;

/**
 * Keeps the FullTextSearch index in sync with the mail database by reacting to
 * message lifecycle events.
 *
 * New/synced messages are queued for indexing; deleted messages are marked for
 * removal. Flag changes trigger a re-index since the FTS framework does not
 * support partial document updates.
 *
 * All operations are best-effort — errors are logged and swallowed so that a
 * failing FTS platform never disrupts normal mail synchronisation.
 *
 * @template-implements IEventListener<Event|NewMessagesSynchronized|MessageDeletedEvent|MessageFlaggedEvent>
 */
class MailFullTextSearchListener implements IEventListener {
	public function __construct(
		private IFullTextSearchManager $ftsManager,
		private MessageMapper $messageMapper,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$this->ftsManager->isAvailable()) {
			return;
		}

		try {
			if ($event instanceof NewMessagesSynchronized) {
				$this->onNewMessages($event);
			} elseif ($event instanceof MessageDeletedEvent) {
				$this->onMessageDeleted($event);
			} elseif ($event instanceof MessageFlaggedEvent) {
				$this->onMessageFlagged($event);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'MailFullTextSearchListener: failed to update FTS index: {error}',
				['error' => $e->getMessage()],
			);
		}
	}

	private function onNewMessages(NewMessagesSynchronized $event): void {
		$userId = $event->getAccount()->getUserId();
		foreach ($event->getMessages() as $message) {
			$this->ftsManager->createIndex(
				'mail',
				(string)$message->getId(),
				$userId,
				IIndex::INDEX_FULL,
			);
		}
	}

	private function onMessageDeleted(MessageDeletedEvent $event): void {
		$this->ftsManager->updateIndexStatus(
			'mail',
			(string)$event->getMessageId(),
			IIndex::INDEX_REMOVE,
		);
	}

	private function onMessageFlagged(MessageFlaggedEvent $event): void {
		// Flag changes require a full re-index since FTS does not support
		// partial document updates. The userId is retrieved from the account
		// on the event.
		$userId = $event->getAccount()->getUserId();
		$mailbox = $event->getMailbox();

		// Look up the DB message ID from the IMAP UID
		$messages = $this->messageMapper->findByUids($mailbox, [$event->getUid()]);
		foreach ($messages as $message) {
			$this->ftsManager->createIndex(
				'mail',
				(string)$message->getId(),
				$userId,
				IIndex::INDEX_META,
			);
		}
	}
}
