/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export default class RequestAbortedError extends Error {
	constructor() {
		super('Request was aborted')
		this.name = RequestAbortedError.getName()
	}

	static getName() {
		return 'RequestAbortedError'
	}
}
