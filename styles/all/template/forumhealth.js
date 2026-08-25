/**
 * Forum Health & Intelligence — duplicate hint.
 *
 * Watches the subject field while somebody starts a new topic and, once they
 * have typed something substantial and then paused, asks the server whether an
 * existing discussion already covers it.
 *
 * Three deliberate constraints:
 *
 *   It never blocks. The hint appears beneath the subject line and the post
 *   button keeps working throughout. A wrong suggestion should cost a glance,
 *   not a submission.
 *
 *   It is quiet. The request fires after typing stops, not on every keystroke,
 *   and never twice for the same text.
 *
 *   It fails silently. A network error, an unexpected response or a missing
 *   endpoint leaves the page exactly as it was.
 *
 * All user-facing wording arrives from the server or from data attributes
 * written by the template, so nothing here needs translating.
 */
(function () {
	'use strict';

	var DEBOUNCE_MS = 600;
	var MIN_LENGTH = 8;

	var container = document.getElementById('fh-duplicate-hint');

	if (!container) {
		return;
	}

	var endpoint = container.getAttribute('data-endpoint');

	if (!endpoint) {
		return;
	}

	var subject = document.querySelector('input[name="subject"]');

	if (!subject) {
		return;
	}

	var timer = null;
	var lastQuery = '';
	var dismissed = false;
	var pending = null;

	/**
	 * Remove every child of the hint container.
	 */
	function clear() {
		while (container.firstChild) {
			container.removeChild(container.firstChild);
		}
	}

	/**
	 * Render the suggestions.
	 *
	 * Built with createElement and textContent rather than innerHTML: the
	 * titles come from the database and must never be interpreted as markup,
	 * whatever escaping happened upstream.
	 *
	 * @param {Object} data Response payload.
	 */
	function render(data) {
		clear();

		var heading = document.createElement('h3');
		heading.textContent = data.heading || '';
		container.appendChild(heading);

		var list = document.createElement('ul');

		data.topics.forEach(function (topic) {
			var item = document.createElement('li');
			var link = document.createElement('a');

			link.href = topic.url;
			link.textContent = topic.title;
			link.target = '_blank';
			link.rel = 'noopener';

			item.appendChild(link);
			list.appendChild(item);
		});

		container.appendChild(list);

		var actions = document.createElement('div');
		actions.className = 'fh-hint-actions';

		var dismiss = document.createElement('button');
		dismiss.type = 'button';
		dismiss.className = 'button button-secondary';
		dismiss.textContent = container.getAttribute('data-label-dismiss') || '';

		dismiss.addEventListener('click', function () {
			// Once waved away, stay away: repeatedly re-offering the same
			// suggestion while somebody is trying to write is an irritation.
			dismissed = true;
			hide();
		});

		actions.appendChild(dismiss);
		container.appendChild(actions);

		container.hidden = false;
	}

	/**
	 * Hide the hint.
	 */
	function hide() {
		container.hidden = true;
		clear();
	}

	/**
	 * Ask the server about the current subject.
	 *
	 * @param {string} value Subject text.
	 */
	function lookup(value) {
		if (pending) {
			pending.abort();
			pending = null;
		}

		var url = endpoint
			+ (endpoint.indexOf('?') === -1 ? '?' : '&')
			+ 'title=' + encodeURIComponent(value)
			+ '&hash=' + encodeURIComponent(container.getAttribute('data-hash') || '');

		var request = new XMLHttpRequest();
		pending = request;

		request.open('GET', url, true);
		request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

		request.onload = function () {
			pending = null;

			if (request.status !== 200) {
				return;
			}

			var data;

			try {
				data = JSON.parse(request.responseText);
			} catch (e) {
				// A malformed response is treated exactly like no response.
				return;
			}

			if (!data || !data.found || !data.topics || !data.topics.length) {
				hide();
				return;
			}

			if (!dismissed) {
				render(data);
			}
		};

		request.onerror = function () {
			pending = null;
		};

		request.send();
	}

	/**
	 * Schedule a lookup once typing settles.
	 */
	function schedule() {
		if (dismissed) {
			return;
		}

		var value = subject.value.trim();

		if (timer) {
			window.clearTimeout(timer);
		}

		if (value.length < MIN_LENGTH || value === lastQuery) {
			return;
		}

		timer = window.setTimeout(function () {
			lastQuery = value;
			lookup(value);
		}, DEBOUNCE_MS);
	}

	subject.addEventListener('input', schedule);
	subject.addEventListener('blur', schedule);
}());
