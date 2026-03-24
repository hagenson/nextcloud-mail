<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Command;

use OCA\Mail\Account;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\MailboxMapper;
use OCA\Mail\Db\Message;
use OCA\Mail\Db\MessageMapper;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\IMAP\MessageMapper as ImapMessageMapper;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\Search\ElasticSearchIndexer;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ mail:search:index
 *
 * (Re-)indexes all cached mail messages into ElasticSearch.
 * Run this once after enabling elasticsearch_enabled, and again whenever you
 * want to rebuild the index from scratch (e.g. after mapping changes).
 *
 * Options:
 *   --user=<uid>  Only index mail for the given Nextcloud user ID
 *   --reset       Delete and recreate the index before indexing
 *   --batch=N     Messages per DB batch (default 100)
 */
class IndexMailboxes extends Command {
	public function __construct(
		private ElasticSearchIndexer $indexer,
		private AccountService $accountService,
		private MailboxMapper $mailboxMapper,
		private MessageMapper $messageMapper,
		private IUserManager $userManager,
		private IMAPClientFactory $clientFactory,
		private ImapMessageMapper $imapMapper,
		private LoggerInterface $logger,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this->setName('mail:search:index')
			->setDescription('Index all cached mail messages into ElasticSearch')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only index mail for this user')
			->addOption('reset', null, InputOption::VALUE_NONE, 'Delete and recreate the index before indexing')
			->addOption('batch', 'b', InputOption::VALUE_REQUIRED, 'Messages per DB batch', '100');
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->indexer->isAvailable()) {
			$output->writeln('<error>ElasticSearch indexing is not enabled.</error>');
			$output->writeln('Enable it with:');
			$output->writeln('  occ config:app:set mail elasticsearch_enabled --value=1');
			$output->writeln('  occ config:app:set mail elasticsearch_host --value=http://localhost:9200');
			return Command::FAILURE;
		}

		if ($input->getOption('reset')) {
			$output->writeln('Recreating ElasticSearch index…');
			try {
				$this->indexer->ensureIndexExists();
				$output->writeln('<info>Index ready.</info>');
			} catch (\Exception $e) {
				$output->writeln('<error>Failed to create index: ' . $e->getMessage() . '</error>');
				return Command::FAILURE;
			}
		} else {
			try {
				$this->indexer->ensureIndexExists();
			} catch (\Exception $e) {
				$output->writeln('<error>ElasticSearch is unreachable: ' . $e->getMessage() . '</error>');
				return Command::FAILURE;
			}
		}

		$batchSize  = max(1, (int)$input->getOption('batch'));
		$filterUser = $input->getOption('user');

		$accounts = $this->resolveAccounts($filterUser, $output);
		if ($accounts === null) {
			return Command::FAILURE;
		}

		$totalIndexed = 0;

		foreach ($accounts as $account) {
			$userId   = $account->getUserId();
			$mailboxes = $this->mailboxMapper->findAll($account);

			foreach ($mailboxes as $mailbox) {
				$allIds = $this->messageMapper->findAllIds($mailbox);
				$chunks = array_chunk($allIds, $batchSize);
				$count  = count($allIds);

				if ($count === 0) {
					continue;
				}

				$output->writeln(sprintf(
					'Indexing mailbox <comment>%s</comment> for user <comment>%s</comment> (%d messages)…',
					$mailbox->getName(),
					$userId,
					$count
				));

				$progress = new ProgressBar($output, $count);
				$progress->start();

				foreach ($chunks as $idChunk) {
					$messages  = $this->messageMapper->findByIds($userId, $idChunk, 'DESC');
					$bodyTexts = $this->fetchBodyTexts($account, $mailbox, $messages);

					foreach ($messages as $message) {
						$bodyText = $bodyTexts[$message->getUid()] ?? '';
						$this->indexer->indexMessage($userId, $message, $bodyText);
						$totalIndexed++;
					}
					$progress->advance(count($idChunk));
				}

				$progress->finish();
				$output->writeln('');
			}
		}

		$output->writeln(sprintf('<info>Done. Indexed %d message(s).</info>', $totalIndexed));
		return Command::SUCCESS;
	}

	/**
	 * Bulk-fetches full body text for a batch of messages from IMAP.
	 * Returns uid => plain-text body. Falls back to [] on IMAP error so that
	 * callers degrade gracefully to the 255-char previewText in the DB.
	 *
	 * @param Message[] $messages
	 * @return array<int, string>  uid => body text
	 */
	private function fetchBodyTexts(Account $account, Mailbox $mailbox, array $messages): array {
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
				'IndexMailboxes: could not fetch body text from IMAP for mailbox {mailbox}: {error}',
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

	/**
	 * @return Account[]|null  null on error
	 */
	private function resolveAccounts(?string $filterUser, OutputInterface $output): ?array {
		if ($filterUser !== null) {
			if (!$this->userManager->userExists($filterUser)) {
				$output->writeln("<error>User '$filterUser' does not exist.</error>");
				return null;
			}
			return $this->accountService->findByUserId($filterUser);
		}

		return array_map(
			static fn (\OCA\Mail\Db\MailAccount $a) => new \OCA\Mail\Account($a),
			$this->accountService->getAllAcounts()
		);
	}
}
