<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\Search;

use Horde_Imap_Client;
use OCA\Mail\Db\Tag;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Translates a SearchQuery into an ElasticSearch query and returns matching
 * Nextcloud database message IDs.
 */
class ElasticSearchProvider {
	/** Maps Horde/IMAP flag constants to ES document field names */
	private const FLAG_FIELD_MAP = [
		Horde_Imap_Client::FLAG_ANSWERED => 'flag_answered',
		Horde_Imap_Client::FLAG_SEEN     => 'flag_seen',
		Horde_Imap_Client::FLAG_FLAGGED  => 'flag_flagged',
		Horde_Imap_Client::FLAG_DELETED  => 'flag_deleted',
		Tag::LABEL_IMPORTANT             => 'flag_important',
		'$junk'                          => 'flag_junk',
		'$notjunk'                       => 'flag_notjunk',
	];

	public function __construct(
		private ElasticSearchIndexer $indexer,
		private IClientService $clientService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool {
		return $this->indexer->isAvailable();
	}

	/**
	 * Execute a SearchQuery against ElasticSearch and return matching DB message IDs.
	 *
	 * @param string      $userId     Nextcloud user ID — used to scope results
	 * @param SearchQuery $query      The parsed search query
	 * @param int|null    $mailboxId  When set, restrict results to this mailbox
	 * @param int|null    $limit      Maximum number of IDs to return
	 * @param string      $sortOrder  'DESC' (newest first) or 'ASC' (oldest first)
	 *
	 * @return int[]  Nextcloud database message IDs
	 */
	public function findMatchingIds(
		string $userId,
		SearchQuery $query,
		?int $mailboxId,
		?int $limit,
		string $sortOrder = 'DESC',
	): array {
		$esQuery = $this->buildQuery($userId, $query, $mailboxId);
		$size = $limit ?? 50;

		$payload = [
			'size'    => $size,
			'sort'    => [['sent_at' => strtolower($sortOrder)]],
			'_source' => ['id'],
			'query'   => $esQuery,
		];

		if ($query->getCursor() !== null) {
			$payload['search_after'] = [$query->getCursor()];
		}

		$host  = rtrim($this->config->getAppValue('mail', 'elasticsearch_host', 'http://localhost:9200'), '/');
		$index = $this->config->getAppValue('mail', 'elasticsearch_index', 'nextcloud_mail');
		$url   = $host . '/' . $index . '/_search';

		$clientOptions = [];
		$username = $this->config->getAppValue('mail', 'elasticsearch_username', '');
		$password = $this->config->getAppValue('mail', 'elasticsearch_password', '');
		if ($username !== '' && $password !== '') {
			$clientOptions['auth'] = [$username, $password];
		}

		try {
			$client   = $this->clientService->newClient();
			$response = $client->post($url, array_merge($clientOptions, [
				'body'    => json_encode($payload, JSON_THROW_ON_ERROR),
				'headers' => ['Content-Type' => 'application/json'],
			]));

			$body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
			return array_map(
				static fn (array $hit) => (int)$hit['_source']['id'],
				$body['hits']['hits'] ?? []
			);
		} catch (\Exception $e) {
			$this->logger->warning('ElasticSearch query failed: {error}', ['error' => $e->getMessage()]);
			return [];
		}
	}

	// -------------------------------------------------------------------------
	// Query building
	// -------------------------------------------------------------------------

	private function buildQuery(string $userId, SearchQuery $query, ?int $mailboxId): array {
		$must = [];

		// Always scope to the current user
		$must[] = ['term' => ['user_id' => $userId]];

		// Optionally scope to a single mailbox
		if ($mailboxId !== null) {
			$must[] = ['term' => ['mailbox_id' => $mailboxId]];
		}

		// Date range
		$rangeClause = $this->buildDateRange($query);
		if ($rangeClause !== null) {
			$must[] = $rangeClause;
		}

		// Boolean flags (must / must-not)
		foreach ($query->getFlags() as $flag) {
			$field = self::FLAG_FIELD_MAP[$flag->getFlag()] ?? null;
			if ($field === null) {
				continue;
			}
			$must[] = ['term' => [$field => $flag->isSet()]];
		}

		// Compound flag expressions
		foreach ($query->getFlagExpressions() as $expression) {
			$clause = $this->buildFlagExpression($expression);
			if ($clause !== null) {
				$must[] = $clause;
			}
		}

		// Tags (all must match)
		if (!empty($query->getTags())) {
			$must[] = ['terms' => ['tags' => $query->getTags()]];
		}

		// Attachment filter
		if ($query->getHasAttachments()) {
			$must[] = ['term' => ['flag_attachments' => true]];
		}

		// Text field queries (subject / from / to / cc / bcc / body)
		$textClauses = $this->buildTextClauses($query);
		if (!empty($textClauses)) {
			if ($query->getMatch() === 'anyof') {
				$must[] = ['bool' => ['should' => $textClauses, 'minimum_should_match' => 1]];
			} else {
				foreach ($textClauses as $clause) {
					$must[] = $clause;
				}
			}
		}

		if (empty($must)) {
			return ['match_all' => (object)[]];
		}

		return ['bool' => ['must' => $must]];
	}

	private function buildDateRange(SearchQuery $query): ?array {
		$range = [];
		if ($query->getStart() !== null) {
			$range['gte'] = (int)$query->getStart();
		}
		if ($query->getEnd() !== null) {
			$range['lte'] = (int)$query->getEnd();
		}
		if (empty($range)) {
			return null;
		}
		return ['range' => ['sent_at' => $range]];
	}

	/** @return array[] */
	private function buildTextClauses(SearchQuery $query): array {
		$clauses = [];

		foreach ($query->getSubjects() as $term) {
			$clauses[] = ['match' => ['subject' => ['query' => $term, 'operator' => 'and']]];
		}

		foreach ($query->getBodies() as $term) {
			$clauses[] = ['match' => ['body' => ['query' => $term, 'operator' => 'and']]];
		}

		foreach ($query->getFrom() as $term) {
			$clauses[] = ['bool' => ['should' => [
				['wildcard' => ['from_email' => ['value' => '*' . strtolower($term) . '*']]],
				['match'    => ['from_label' => $term]],
			], 'minimum_should_match' => 1]];
		}

		foreach ($query->getTo() as $term) {
			$clauses[] = ['bool' => ['should' => [
				['wildcard' => ['to_email' => ['value' => '*' . strtolower($term) . '*']]],
				['match'    => ['to_label' => $term]],
			], 'minimum_should_match' => 1]];
		}

		foreach ($query->getCc() as $term) {
			$clauses[] = ['bool' => ['should' => [
				['wildcard' => ['cc_email' => ['value' => '*' . strtolower($term) . '*']]],
				['match'    => ['cc_label' => $term]],
			], 'minimum_should_match' => 1]];
		}

		foreach ($query->getBcc() as $term) {
			$clauses[] = ['bool' => ['should' => [
				['wildcard' => ['bcc_email' => ['value' => '*' . strtolower($term) . '*']]],
				['match'    => ['bcc_label' => $term]],
			], 'minimum_should_match' => 1]];
		}

		return $clauses;
	}

	private function buildFlagExpression(FlagExpression $expression): ?array {
		$operandClauses = [];
		foreach ($expression->getOperands() as $operand) {
			if ($operand instanceof Flag) {
				$field = self::FLAG_FIELD_MAP[$operand->getFlag()] ?? null;
				if ($field !== null) {
					$operandClauses[] = ['term' => [$field => $operand->isSet()]];
				}
			} elseif ($operand instanceof FlagExpression) {
				$nested = $this->buildFlagExpression($operand);
				if ($nested !== null) {
					$operandClauses[] = $nested;
				}
			}
		}

		if (empty($operandClauses)) {
			return null;
		}

		$esOperator = $expression->getOperator() === 'or' ? 'should' : 'must';
		$clause = ['bool' => [$esOperator => $operandClauses]];
		if ($esOperator === 'should') {
			$clause['bool']['minimum_should_match'] = 1;
		}
		return $clause;
	}
}
