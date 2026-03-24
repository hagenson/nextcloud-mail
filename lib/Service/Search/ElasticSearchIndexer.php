<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\Search;

use OCA\Mail\Address;
use OCA\Mail\AddressList;
use OCA\Mail\Db\Message;
use OCA\Mail\Db\Tag;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Manages ElasticSearch indexing for mail messages.
 *
 * Configuration (stored via occ config:app:set mail):
 *   elasticsearch_enabled  - '1' to enable, '0' (default) to disable
 *   elasticsearch_host     - ES base URL, e.g. http://localhost:9200
 *   elasticsearch_index    - Index name, default 'nextcloud_mail'
 *   elasticsearch_username - Optional HTTP Basic auth username
 *   elasticsearch_password - Optional HTTP Basic auth password
 */
class ElasticSearchIndexer {
	private const DEFAULT_HOST = 'http://localhost:9200';
	private const DEFAULT_INDEX = 'nextcloud_mail';

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool {
		return $this->config->getAppValue('mail', 'elasticsearch_enabled', '0') === '1';
	}

	private function getHost(): string {
		return rtrim($this->config->getAppValue('mail', 'elasticsearch_host', self::DEFAULT_HOST), '/');
	}

	private function getIndex(): string {
		return $this->config->getAppValue('mail', 'elasticsearch_index', self::DEFAULT_INDEX);
	}

	private function getClientOptions(): array {
		$options = [];
		$username = $this->config->getAppValue('mail', 'elasticsearch_username', '');
		$password = $this->config->getAppValue('mail', 'elasticsearch_password', '');
		if ($username !== '' && $password !== '') {
			$options['auth'] = [$username, $password];
		}
		return $options;
	}

	/**
	 * Index a single message. Safe to call even if ES is unavailable — errors are logged and swallowed.
	 */
	public function indexMessage(string $userId, Message $message, string $bodyText = ''): void {
		if (!$this->isAvailable()) {
			return;
		}

		$doc = $this->buildDocument($userId, $message, $bodyText);
		$url = $this->getHost() . '/' . $this->getIndex() . '/_doc/' . $message->getId();

		try {
			$client = $this->clientService->newClient();
			$client->put($url, array_merge($this->getClientOptions(), [
				'body' => json_encode($doc, JSON_THROW_ON_ERROR),
				'headers' => ['Content-Type' => 'application/json'],
			]));
		} catch (\Exception $e) {
			$this->logger->warning('Failed to index message {id} in ElasticSearch: {error}', [
				'id' => $message->getId(),
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Remove a message document from the index. Safe to call even if ES is unavailable.
	 */
	public function deleteMessage(int $messageId): void {
		if (!$this->isAvailable()) {
			return;
		}

		$url = $this->getHost() . '/' . $this->getIndex() . '/_doc/' . $messageId;

		try {
			$client = $this->clientService->newClient();
			$client->delete($url, $this->getClientOptions());
		} catch (\Exception $e) {
			// A 404 on delete is harmless; log everything else
			$this->logger->debug('Failed to delete message {id} from ElasticSearch: {error}', [
				'id' => $messageId,
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Partially update a single flag field on an indexed document.
	 */
	public function updateFlag(int $messageId, string $flag, bool $value): void {
		if (!$this->isAvailable()) {
			return;
		}

		$fieldMap = [
			'seen'               => 'flag_seen',
			'flagged'            => 'flag_flagged',
			'answered'           => 'flag_answered',
			'deleted'            => 'flag_deleted',
			'draft'              => 'flag_draft',
			'forwarded'          => 'flag_forwarded',
			'$junk'              => 'flag_junk',
			'$notjunk'           => 'flag_notjunk',
			Tag::LABEL_IMPORTANT => 'flag_important',
		];

		$field = $fieldMap[$flag] ?? null;
		if ($field === null) {
			return;
		}

		$url = $this->getHost() . '/' . $this->getIndex() . '/_update/' . $messageId;

		try {
			$client = $this->clientService->newClient();
			$client->post($url, array_merge($this->getClientOptions(), [
				'body' => json_encode(['doc' => [$field => $value]], JSON_THROW_ON_ERROR),
				'headers' => ['Content-Type' => 'application/json'],
			]));
		} catch (\Exception $e) {
			$this->logger->debug('Failed to update flag for message {id} in ElasticSearch: {error}', [
				'id' => $messageId,
				'error' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Create the index with field mappings if it does not already exist.
	 *
	 * @throws \Exception when the index cannot be created
	 */
	public function ensureIndexExists(): void {
		$indexUrl = $this->getHost() . '/' . $this->getIndex();
		$client = $this->clientService->newClient();

		try {
			$client->get($indexUrl, $this->getClientOptions());
			return; // index already exists
		} catch (\Exception $e) {
			// fall through to creation
		}

		$client->put($indexUrl, array_merge($this->getClientOptions(), [
			'body' => json_encode($this->getIndexMapping(), JSON_THROW_ON_ERROR),
			'headers' => ['Content-Type' => 'application/json'],
		]));
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function buildDocument(string $userId, Message $message, string $bodyText = ''): array {
		return [
			'id'             => $message->getId(),
			'uid'            => $message->getUid(),
			'mailbox_id'     => $message->getMailboxId(),
			'user_id'        => $userId,
			'message_id'     => $message->getMessageId(),
			'subject'        => $message->getSubject() ?? '',
			'body'           => $bodyText !== '' ? $bodyText : ($message->getPreviewText() ?? ''),
			'sent_at'        => $message->getSentAt(),
			'from_email'     => $this->extractEmails($message->getFrom()),
			'from_label'     => $this->extractLabels($message->getFrom()),
			'to_email'       => $this->extractEmails($message->getTo()),
			'to_label'       => $this->extractLabels($message->getTo()),
			'cc_email'       => $this->extractEmails($message->getCc()),
			'cc_label'       => $this->extractLabels($message->getCc()),
			'bcc_email'      => $this->extractEmails($message->getBcc()),
			'bcc_label'      => $this->extractLabels($message->getBcc()),
			'flag_seen'        => (bool)$message->getFlagSeen(),
			'flag_flagged'     => (bool)$message->getFlagFlagged(),
			'flag_answered'    => (bool)$message->getFlagAnswered(),
			'flag_deleted'     => (bool)$message->getFlagDeleted(),
			'flag_attachments' => (bool)$message->getFlagAttachments(),
			'flag_important'   => (bool)$message->getFlagImportant(),
			'flag_junk'        => (bool)$message->getFlagJunk(),
			'tags'             => array_map(
				static fn (Tag $tag) => $tag->getImapLabel(),
				$message->getTags()
			),
		];
	}

	/** @return string[] */
	private function extractEmails(AddressList $list): array {
		$emails = [];
		foreach ($list->iterate() as $address) {
			$email = $address->getEmail();
			if ($email !== null) {
				$emails[] = $email;
			}
		}
		return $emails;
	}

	/** @return string[] */
	private function extractLabels(AddressList $list): array {
		$labels = [];
		foreach ($list->iterate() as $address) {
			$label = $address->getLabel();
			if ($label !== null) {
				$labels[] = $label;
			}
		}
		return $labels;
	}

	private function getIndexMapping(): array {
		return [
			'mappings' => [
				'properties' => [
					'id'             => ['type' => 'integer'],
					'uid'            => ['type' => 'integer'],
					'mailbox_id'     => ['type' => 'integer'],
					'user_id'        => ['type' => 'keyword'],
					'message_id'     => ['type' => 'keyword'],
					'subject'        => ['type' => 'text', 'analyzer' => 'standard'],
					'body'           => ['type' => 'text', 'analyzer' => 'standard'],
					'sent_at'        => ['type' => 'date', 'format' => 'epoch_second'],
					'from_email'     => ['type' => 'keyword'],
					'from_label'     => ['type' => 'text', 'analyzer' => 'standard'],
					'to_email'       => ['type' => 'keyword'],
					'to_label'       => ['type' => 'text', 'analyzer' => 'standard'],
					'cc_email'       => ['type' => 'keyword'],
					'cc_label'       => ['type' => 'text', 'analyzer' => 'standard'],
					'bcc_email'      => ['type' => 'keyword'],
					'bcc_label'      => ['type' => 'text', 'analyzer' => 'standard'],
					'flag_seen'        => ['type' => 'boolean'],
					'flag_flagged'     => ['type' => 'boolean'],
					'flag_answered'    => ['type' => 'boolean'],
					'flag_deleted'     => ['type' => 'boolean'],
					'flag_attachments' => ['type' => 'boolean'],
					'flag_important'   => ['type' => 'boolean'],
					'flag_junk'        => ['type' => 'boolean'],
					'tags'             => ['type' => 'keyword'],
				],
			],
		];
	}
}
