# ElasticSearch Integration for Nextcloud Mail

This document describes how to enable and configure the ElasticSearch-backed full-text search for the Nextcloud Mail app.

## Prerequisites

- A running ElasticSearch instance (v7 or v8), reachable from the Nextcloud server
- Nextcloud Mail app installed and configured with at least one IMAP account

## Local development quickstart

A helper script is provided to spin up a single-node, no-auth ElasticSearch 8
container via Docker:

```bash
# Start (pulls image on first run, waits until healthy)
./start_elasticsearch.sh

# Stop and remove the container
./start_elasticsearch.sh stop

# Tail container logs
./start_elasticsearch.sh logs

# Print cluster health
./start_elasticsearch.sh status
```

The script prints the exact `occ` commands to run once ElasticSearch is up.

## Configuration

All settings are stored in Nextcloud's app config and can be managed with `occ config:app:set`.

### Required settings

```bash
# Enable ElasticSearch integration (disabled by default)
occ config:app:set mail elasticsearch_enabled --value=1

# Base URL of your ElasticSearch instance
occ config:app:set mail elasticsearch_host --value=http://localhost:9200
```

### Optional settings

```bash
# Name of the ElasticSearch index (default: nextcloud_mail)
occ config:app:set mail elasticsearch_index --value=nextcloud_mail

# HTTP Basic auth credentials (if your ES cluster requires authentication)
occ config:app:set mail elasticsearch_username --value=elastic
occ config:app:set mail elasticsearch_password --value=changeme
```

## Building the initial index

After enabling the integration, run the following command to index all messages that are already cached in the Nextcloud database:

```bash
occ mail:search:index
```

### Options

| Option | Description |
|--------|-------------|
| `--user=<uid>` | Only index mail for the specified Nextcloud user |
| `--reset` | Delete and recreate the index before indexing (use after mapping changes) |
| `--batch=<N>` | Number of messages fetched from the DB per batch (default: `100`) |

### Examples

```bash
# Index all users
occ mail:search:index

# Index a single user
occ mail:search:index --user=alice

# Rebuild the index from scratch
occ mail:search:index --reset

# Use a larger batch size for faster indexing on well-resourced servers
occ mail:search:index --batch=500
```

## Ongoing sync

Once enabled, new messages are indexed automatically every time a mailbox is synced (via background job or manual sync). Deleted messages are removed from the index at the same time.

## Disabling the integration

```bash
occ config:app:set mail elasticsearch_enabled --value=0
```

When disabled, all search operations fall back to the original behaviour: metadata searches run against the Nextcloud database, and body searches are delegated live to the IMAP server.

## Supported search filters

The following filter tokens (used in the mail app search bar) are handled by ElasticSearch when the integration is active:

| Filter | Example | Description |
|--------|---------|-------------|
| `subject:` | `subject:invoice` | Full-text match on the message subject |
| `body:` | `body:meeting` | Full-text match on the message body |
| `from:` | `from:alice@example.com` | Matches sender email address or display name |
| `to:` | `to:bob` | Matches recipient email address or display name |
| `cc:` | `cc:carol` | Matches CC recipient email address or display name |
| `bcc:` | `bcc:dave` | Matches BCC recipient email address or display name |
| `start:` | `start:1700000000` | Messages sent on or after this Unix timestamp |
| `end:` | `end:1710000000` | Messages sent on or before this Unix timestamp |
| `is:` / `not:` | `is:unread`, `not:flagged` | Flag filters (seen, flagged, answered, deleted) |
| `tags:` | `tags:$label1` | Messages tagged with the given IMAP label |
| `flags:attachments` | `flags:attachments` | Messages that have attachments |
| `match:anyof` | `subject:foo match:anyof from:bar` | Match any text field (OR logic) instead of all (AND) |

### Cross-mailbox search

ElasticSearch enables true cross-mailbox full-text search, including body content — something the original IMAP-only approach could not do efficiently. Global searches (via the Nextcloud unified search bar) now query ElasticSearch and return results from all mailboxes in a single request.

## Troubleshooting

### Check connectivity

```bash
# Verify the ES cluster is reachable and the index exists
curl http://localhost:9200/nextcloud_mail
```

### Re-index after body content changes

The body field in the index is populated from the cached `preview_text` (up to 255 characters). If you need richer full-text body content, run a re-index after extending `ElasticSearchIndexer::buildDocument()` to include a fuller body source:

```bash
occ mail:search:index --reset
```

### View index mapping

```bash
curl http://localhost:9200/nextcloud_mail/_mapping
```

### Check document count

```bash
curl http://localhost:9200/nextcloud_mail/_count
```
