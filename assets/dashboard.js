/**
 * Ars Nova Season Dashboard — vanilla JS, no dependencies.
 *
 * Renders the Season Tracker as a Kanban board, grouped list, or a
 * two-column Open | Done checklist, with filters, summary tiles, per-user
 * default views, and (when write-back is enabled) native HTML5 drag-and-drop
 * for Status, an inline Owner dropdown, checklist done/undone checkboxes
 * with a named Undo toast, a "+ New Task" modal, a task-detail modal
 * (Notes / Links / Parent task), a clickable color dot, custom tags, and
 * per-user drag-ordering of the checklist Open column (user meta — works
 * even when sheet write-back is off).
 *
 * v1.3.0: "Send to Claude Desktop" hand-off (claude:// deep link with a
 * pre-filled ans-task-runner brief + web fallback) and a read-only progress
 * meter / step list fed by the tracker's Progress % (R) and Steps (S)
 * columns.
 *
 * All task text is inserted via textContent — never innerHTML — so sheet
 * content can't inject markup.
 */
(function () {
	'use strict';

	if (typeof window.ansDashConfig === 'undefined') {
		return;
	}

	var cfg = window.ansDashConfig;
	var root = document.getElementById('ans-dash-app');
	if (!root) {
		return;
	}

	/* ------------------------------------------------------------------ */
	/* State                                                              */
	/* ------------------------------------------------------------------ */

	var state = {
		tasks: [],
		today: '',
		fetchedAt: 0,
		writeEnabled: !!cfg.writeEnabled,
		filters: {
			owner: cfg.prefs.owner || '',
			bucket: cfg.prefs.bucket || '',
			priority: cfg.prefs.priority || '',
			search: '',
			myTasks: !!cfg.prefs.my_tasks
		},
		layout: ['board', 'list', 'checklist'].indexOf(cfg.prefs.layout) !== -1 ? cfg.prefs.layout : 'board',
		hiddenFields: (cfg.prefs.hidden_fields || []).slice(),
		collapsedBuckets: (cfg.prefs.collapsed_buckets || []).slice(),
		currentOwner: cfg.currentOwner || '',
		order: (cfg.order || []).slice(),          // Per-user checklist drag-order (Task IDs).
		customTags: (cfg.customBuckets || []).slice() // Site-wide custom tag names.
	};

	var els = {
		toolbar: root.querySelector('.ans-dash-toolbar'),
		summary: root.querySelector('.ans-dash-summary'),
		ownerBanner: root.querySelector('.ans-dash-owner-banner'),
		notice: root.querySelector('.ans-dash-notice'),
		body: root.querySelector('.ans-dash-body')
	};

	var CARD_FIELDS = ['priority', 'owner', 'due', 'bucket', 'event'];
	var FIELD_LABELS = { priority: 'Priority', owner: 'Owner', due: 'Due', bucket: 'Tag', event: 'Event' };
	var savePrefsTimer = null;
	var noticeTimer = null;
	var undoToast = null;      // Active undo toast element (feature: undo).
	var undoTimer = null;
	var draggingRow = null;    // Row being dragged in the checklist Open column.
	var colorPop = null;       // Active color-palette popover.

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (typeof text === 'string') {
			node.textContent = text;
		}
		return node;
	}

	function option(value, label, selected) {
		var o = document.createElement('option');
		o.value = value;
		o.textContent = label;
		if (selected) {
			o.selected = true;
		}
		return o;
	}

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (k) {
			body.append(k, data[k]);
		});
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (!json || !json.success) {
					var msg = json && json.data && json.data.message ? json.data.message : cfg.i18n.saveError;
					throw new Error(msg);
				}
				return json.data;
			});
	}

	function notify(message, isError) {
		els.notice.hidden = false;
		els.notice.textContent = message;
		els.notice.classList.toggle('is-error', !!isError);
		clearTimeout(noticeTimer);
		noticeTimer = setTimeout(function () {
			els.notice.hidden = true;
		}, isError ? 8000 : 3000);
	}

	function fmt(template, value) {
		return String(template).replace('%s', value);
	}

	function findTask(taskId) {
		return state.tasks.find(function (t) { return t['Task ID'] === taskId; }) || null;
	}

	function taskNum(task) {
		var m = /^TSK-(\d+)$/i.exec(task['Task ID'] || '');
		return m ? parseInt(m[1], 10) : 0;
	}

	/* ------------------------------------------------------------------ */
	/* "Send to Claude Desktop" hand-off (v1.3.0)                          */
	/*                                                                     */
	/* claude://claude.ai/new?q=<url-encoded prompt> opens Claude Desktop  */
	/* on a new chat with the composer pre-filled (no auto-send). The      */
	/* brief invokes the ans-task-runner skill for the task. A https://    */
	/* fallback covers machines without the desktop app.                   */
	/* ------------------------------------------------------------------ */

	var CLAUDE_BRIEF_MAX = 13000;

	/**
	 * The pre-filled prompt for one task. If the assembled brief exceeds
	 * CLAUDE_BRIEF_MAX characters, the Notes (Context) section is truncated
	 * and " …[truncated]" is appended so the URL stays under the ~14k limit.
	 */
	function buildClaudeBrief(task) {
		var projectName = cfg.projectName || 'Ars Nova';
		var skillName = cfg.skillName || 'ans-task-runner';
		var id = task['Task ID'] || '';
		var notes = String(task.Notes || '');
		var links = String(task.Links || '');

		function assemble(notesText) {
			return 'Switch this chat into the "' + projectName + '" project, then load the ' + skillName + ' skill and run ' + id + '.\n\n' +
				'TASK — ' + id + ': ' + (task.Task || '') + '\n' +
				'Owner: ' + (task.Owner || 'Unassigned') +
				' · Status: ' + (task.Status || '') +
				' · Due: ' + (task.Due || '—') +
				' · Priority: ' + (task.Priority || '—') +
				' · Tag: ' + (task.Bucket || '—') + '\n\n' +
				'Context:\n' + notesText + '\n\n' +
				'Links:\n' + links + '\n\n' +
				'Follow ' + skillName + ': gather project context + the governing SOP, route to the right tools, web-search best practice, then PROPOSE a plan (with any draft) before doing anything irreversible. Log planned-vs-taken steps and Progress % back to the tracker when you execute.';
		}

		var brief = assemble(notes);
		if (brief.length > CLAUDE_BRIEF_MAX) {
			var marker = ' …[truncated]';
			var overflow = brief.length - CLAUDE_BRIEF_MAX;
			var keep = Math.max(0, notes.length - overflow - marker.length);
			brief = assemble(notes.slice(0, keep) + marker);
			if (brief.length > CLAUDE_BRIEF_MAX) {
				brief = brief.slice(0, CLAUDE_BRIEF_MAX); // Belt & braces.
			}
		}
		return brief;
	}

	/** Deep link into Claude Desktop (new chat, pre-filled composer). */
	function claudeUrl(task) {
		return 'claude://claude.ai/new?q=' + encodeURIComponent(buildClaudeBrief(task));
	}

	/** Same brief on claude.ai for machines without the desktop app. */
	function claudeWebUrl(task) {
		return 'https://claude.ai/new?q=' + encodeURIComponent(buildClaudeBrief(task));
	}

	function sendTaskToClaude(taskId) {
		var task = findTask(taskId);
		if (!task) {
			return;
		}
		window.location.href = claudeUrl(task);
	}

	/**
	 * Compact per-row "→ Claude" button (checklist rows + board cards).
	 * stopPropagation so it never toggles the checkbox or opens the modal.
	 */
	function buildClaudeRowButton(task) {
		var btn = el('button', 'ans-dash-claude-btn', '→ Claude');
		btn.type = 'button';
		btn.title = 'Send to Claude Desktop';
		btn.setAttribute('aria-label', 'Send ' + task['Task ID'] + ' to Claude Desktop');
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			sendTaskToClaude(task['Task ID']);
		});
		return btn;
	}

	/* ------------------------------------------------------------------ */
	/* Progress meter + steps (v1.3.0 — read-only; the ans-task-runner     */
	/* skill writes Progress % / Steps via the Google connector)           */
	/* ------------------------------------------------------------------ */

	var STEP_LINE_RE = /^\s*\[[ xX]\]/;
	var STEP_DONE_RE = /^\s*\[[xX]\]/;

	/** Step lines of task.Steps: lines matching "[ ]" / "[x]" / "[X]". */
	function stepLines(task) {
		return String(task.Steps || '').split(/\r\n|\r|\n/).filter(function (line) {
			return STEP_LINE_RE.test(line);
		});
	}

	/**
	 * {pct, done, total} for a task. pct comes from the sheet's Progress %
	 * when it's a number (clamped 0–100); else it's derived from the step
	 * checkboxes; else null (no progress data — render nothing).
	 */
	function progressOf(task) {
		var lines = stepLines(task);
		var total = lines.length;
		var done = lines.filter(function (line) {
			return STEP_DONE_RE.test(line);
		}).length;

		var pct = null;
		var raw = String(task['Progress %'] || '').replace('%', '').trim();
		if (raw !== '' && !isNaN(parseInt(raw, 10))) {
			pct = Math.min(100, Math.max(0, parseInt(raw, 10)));
		} else if (total > 0) {
			pct = Math.round((done / total) * 100);
		}
		return { pct: pct, done: done, total: total };
	}

	/**
	 * Slim horizontal progress bar (track + fill + % label), or null when the
	 * task has no progress data (never show a misleading 0% bar).
	 */
	function buildProgressMeter(task) {
		var p = progressOf(task);
		if (p.pct === null) {
			return null;
		}
		var meter = el('div', 'ans-dash-progress');
		meter.setAttribute('role', 'img');
		meter.setAttribute('aria-label', 'Progress ' + p.pct + '%');
		var track = el('span', 'ans-dash-progress-track');
		var fill = el('span', 'ans-dash-progress-fill' + (p.pct >= 100 ? ' is-complete' : ''));
		fill.style.width = p.pct + '%';
		track.appendChild(fill);
		meter.appendChild(track);
		meter.appendChild(el('span', 'ans-dash-progress-pct', p.pct + '%'));
		return meter;
	}

	/**
	 * Richer, dismissible toast with an Undo button. Auto-hides after ~8s.
	 * Solves "which task did I just check?" — the message names the task.
	 */
	function showUndo(message, undoFn) {
		hideUndo();
		var toast = el('div', 'ans-dash-undo-toast');
		toast.setAttribute('role', 'status');
		toast.setAttribute('aria-live', 'polite');
		toast.appendChild(el('span', 'ans-dash-undo-msg', message));
		var btn = el('button', 'ans-dash-undo-btn', cfg.i18n.undo || 'Undo');
		btn.type = 'button';
		btn.addEventListener('click', function () {
			hideUndo();
			if (typeof undoFn === 'function') {
				undoFn();
			}
		});
		toast.appendChild(btn);
		var closeBtn = el('button', 'ans-dash-undo-close', '×');
		closeBtn.type = 'button';
		closeBtn.setAttribute('aria-label', 'Dismiss');
		closeBtn.addEventListener('click', hideUndo);
		toast.appendChild(closeBtn);
		root.appendChild(toast);
		undoToast = toast;
		undoTimer = setTimeout(hideUndo, 8000);
	}

	function hideUndo() {
		clearTimeout(undoTimer);
		if (undoToast) {
			undoToast.remove();
			undoToast = null;
		}
	}

	/**
	 * Parse a "Y-m-d H:i:s" Done At stamp into e.g. "Done Jul 21, 3:14 PM".
	 */
	function formatDoneAt(s) {
		var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(s || ''));
		if (!m) {
			return s ? 'Done ' + s : '';
		}
		var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		var month = months[parseInt(m[2], 10) - 1] || m[2];
		var day = parseInt(m[3], 10);
		var h = parseInt(m[4], 10);
		var ampm = h >= 12 ? 'PM' : 'AM';
		h = h % 12 || 12;
		return 'Done ' + month + ' ' + day + ', ' + h + ':' + m[5] + ' ' + ampm;
	}

	/**
	 * Task color hex if it matches the server palette; '' otherwise (so an
	 * unexpected sheet value can never become an inline style).
	 */
	function colorHexFor(task) {
		var c = String(task.Color || '').toLowerCase();
		if (c === '') {
			return '';
		}
		var colors = cfg.colors || {};
		var names = Object.keys(colors);
		for (var i = 0; i < names.length; i++) {
			if (String(colors[names[i]]).toLowerCase() === c || names[i].toLowerCase() === c) {
				return String(colors[names[i]]).toLowerCase();
			}
		}
		return '';
	}

	/**
	 * The colored dot: task.Color if set (and valid), else the priority color.
	 * Clickable (palette popover) when write-back is enabled.
	 */
	function buildDot(task) {
		var dot = el('span', 'ans-dash-dot');
		var hex = colorHexFor(task);
		if (hex) {
			dot.style.backgroundColor = hex;
			dot.title = 'Color';
		} else if (task.Priority) {
			dot.classList.add('ans-dash-dot-' + task.Priority.toLowerCase());
			dot.title = task.Priority;
		} else {
			dot.classList.add('ans-dash-dot-none');
		}
		if (state.writeEnabled) {
			dot.classList.add('is-clickable');
			dot.setAttribute('role', 'button');
			dot.tabIndex = 0;
			dot.setAttribute('aria-label', 'Set color for ' + task['Task ID']);
			dot.addEventListener('click', function (e) {
				e.stopPropagation();
				e.preventDefault();
				openColorPopover(dot, task['Task ID']);
			});
			dot.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					openColorPopover(dot, task['Task ID']);
				}
			});
		}
		return dot;
	}

	function closeColorPopover() {
		if (colorPop) {
			document.removeEventListener('mousedown', colorPop._onDocDown);
			document.removeEventListener('keydown', colorPop._onKeydown);
			colorPop.remove();
			colorPop = null;
		}
	}

	function openColorPopover(anchor, taskId) {
		closeColorPopover();
		var task = findTask(taskId);
		if (!task) {
			return;
		}
		var pop = el('div', 'ans-dash-color-pop');
		pop.setAttribute('role', 'menu');
		pop.setAttribute('aria-label', 'Pick a color');

		function swatch(hex, label) {
			var b = el('button', 'ans-dash-color-swatch');
			b.type = 'button';
			b.title = label;
			b.setAttribute('aria-label', label);
			if (hex) {
				b.style.backgroundColor = hex;
			} else {
				b.classList.add('is-auto');
				b.textContent = '×';
			}
			var current = colorHexFor(task);
			if ((hex && current === hex) || (!hex && current === '')) {
				b.classList.add('is-current');
			}
			b.addEventListener('click', function () {
				closeColorPopover();
				var fresh = findTask(taskId);
				if (!fresh) {
					return;
				}
				var previous = fresh.Color || '';
				fresh.Color = hex; // Optimistic.
				renderBody();
				updateTask(taskId, 'Color', hex, function revert() {
					var t = findTask(taskId);
					if (t) {
						t.Color = previous;
					}
					renderBody();
				});
			});
			return b;
		}

		pop.appendChild(swatch('', 'Auto (priority color)'));
		Object.keys(cfg.colors || {}).forEach(function (name) {
			pop.appendChild(swatch(String(cfg.colors[name]).toLowerCase(), name));
		});

		var rect = anchor.getBoundingClientRect();
		pop.style.position = 'fixed';
		pop.style.left = Math.max(8, rect.left - 4) + 'px';
		pop.style.top = (rect.bottom + 6) + 'px';
		document.body.appendChild(pop);
		colorPop = pop;

		pop._onDocDown = function (e) {
			if (!pop.contains(e.target)) {
				closeColorPopover();
			}
		};
		pop._onKeydown = function (e) {
			if (e.key === 'Escape') {
				closeColorPopover();
				anchor.focus();
			}
		};
		document.addEventListener('mousedown', pop._onDocDown);
		document.addEventListener('keydown', pop._onKeydown);
	}

	function isOverdue(task) {
		return task.Due !== '' && task.Status !== 'Done' && task.Due < state.today;
	}

	function isDueSoon(task) {
		if (task.Due === '' || task.Status === 'Done' || task.Due < state.today) {
			return false;
		}
		var due = new Date(task.Due + 'T00:00:00');
		var today = new Date(state.today + 'T00:00:00');
		var diffDays = Math.round((due - today) / 86400000);
		return diffDays >= 0 && diffDays <= 7;
	}

	function fieldHidden(field) {
		return state.hiddenFields.indexOf(field) !== -1;
	}

	function distinctValues(field) {
		var seen = {};
		var out = [];
		state.tasks.forEach(function (t) {
			var v = t[field];
			if (v !== '' && !seen[v]) {
				seen[v] = true;
				out.push(v);
			}
		});
		out.sort(function (a, b) { return a.localeCompare(b); });
		return out;
	}

	function buckets() {
		return distinctValues('Bucket');
	}

	/**
	 * Tag choices everywhere tags are offered: distinct Bucket values from the
	 * sheet merged with the site-wide custom tags (option-backed).
	 */
	function tagChoices() {
		var seen = {};
		var out = [];
		distinctValues('Bucket').concat(state.customTags || []).forEach(function (v) {
			if (v !== '' && !seen[v]) {
				seen[v] = true;
				out.push(v);
			}
		});
		out.sort(function (a, b) { return a.localeCompare(b); });
		return out;
	}

	/**
	 * Comparator for the checklist Open column: tasks in the user's saved
	 * drag-order sort by that order; tasks not in it go to the top,
	 * newest-first (highest TSK number first).
	 */
	function openOrderCompare(a, b) {
		var order = state.order || [];
		var ia = order.indexOf(a['Task ID']);
		var ib = order.indexOf(b['Task ID']);
		if (ia === -1 && ib === -1) {
			return taskNum(b) - taskNum(a); // Newest first.
		}
		if (ia === -1) {
			return -1; // Unordered tasks float to the top.
		}
		if (ib === -1) {
			return 1;
		}
		return ia - ib;
	}

	/**
	 * Persist the Open-column order (per-user, user meta — works with
	 * write-back off). Previously ordered IDs not currently visible are kept
	 * at the end so filtering doesn't wipe the saved order.
	 */
	function persistOrder(visibleIds) {
		var kept = (state.order || []).filter(function (id) {
			return visibleIds.indexOf(id) === -1;
		});
		state.order = visibleIds.concat(kept).slice(0, 500);
		post('ans_dash_save_order', { order: JSON.stringify(state.order) })
			.catch(function (err) {
				notify(err.message, true);
			});
	}

	function filteredTasks() {
		var f = state.filters;
		var q = f.search.toLowerCase();
		var ownerFilter = f.myTasks && state.currentOwner ? state.currentOwner : f.owner;
		return state.tasks.filter(function (t) {
			if (ownerFilter && t.Owner !== ownerFilter) {
				return false;
			}
			if (f.bucket && t.Bucket !== f.bucket) {
				return false;
			}
			if (f.priority && t.Priority !== f.priority) {
				return false;
			}
			if (q) {
				var haystack = (t['Task ID'] + ' ' + t.Task + ' ' + t.Notes + ' ' + t['Concert/Event'] + ' ' + t.Bucket).toLowerCase();
				if (haystack.indexOf(q) === -1) {
					return false;
				}
			}
			return true;
		});
	}

	function schedulePrefsSave() {
		clearTimeout(savePrefsTimer);
		savePrefsTimer = setTimeout(function () {
			var prefs = {
				owner: state.filters.owner,
				bucket: state.filters.bucket,
				priority: state.filters.priority,
				layout: state.layout,
				my_tasks: state.filters.myTasks,
				hidden_fields: state.hiddenFields,
				collapsed_buckets: state.collapsedBuckets
			};
			post('ans_dash_save_prefs', { prefs: JSON.stringify(prefs) }).catch(function (err) {
				notify(err.message, true);
			});
		}, 600);
	}

	/* ------------------------------------------------------------------ */
	/* Toolbar                                                            */
	/* ------------------------------------------------------------------ */

	function renderToolbar() {
		els.toolbar.textContent = '';

		// Owner filter.
		var ownerSel = el('select', 'ans-dash-filter-owner');
		ownerSel.setAttribute('aria-label', 'Owner filter');
		ownerSel.appendChild(option('', 'All owners', state.filters.owner === ''));
		cfg.owners.forEach(function (o) {
			ownerSel.appendChild(option(o, o, state.filters.owner === o));
		});
		ownerSel.addEventListener('change', function () {
			state.filters.owner = ownerSel.value;
			state.filters.myTasks = false;
			schedulePrefsSave();
			render();
		});
		els.toolbar.appendChild(ownerSel);

		// "My tasks" toggle.
		var myBtn = el('button', 'ans-dash-btn ans-dash-my-tasks' + (state.filters.myTasks ? ' is-on' : ''), 'My tasks');
		myBtn.type = 'button';
		myBtn.setAttribute('aria-pressed', state.filters.myTasks ? 'true' : 'false');
		if (!state.currentOwner) {
			myBtn.title = 'Pick your tracker name first (see banner above).';
		}
		myBtn.addEventListener('click', function () {
			if (!state.currentOwner) {
				showOwnerBanner(true);
				return;
			}
			state.filters.myTasks = !state.filters.myTasks;
			schedulePrefsSave();
			render();
		});
		els.toolbar.appendChild(myBtn);

		// Tag filter (the sheet column is still "Bucket"; the UI says "Tag").
		var bucketSel = el('select', 'ans-dash-filter-bucket');
		bucketSel.setAttribute('aria-label', 'Tag filter');
		bucketSel.appendChild(option('', 'All tags', state.filters.bucket === ''));
		tagChoices().forEach(function (b) {
			bucketSel.appendChild(option(b, b, state.filters.bucket === b));
		});
		bucketSel.addEventListener('change', function () {
			state.filters.bucket = bucketSel.value;
			schedulePrefsSave();
			render();
		});
		els.toolbar.appendChild(bucketSel);

		// "+ New tag": persist a tag name so it appears in pickers before any
		// task uses it (site option — works even with write-back off).
		var tagDetails = el('details', 'ans-dash-newtag');
		tagDetails.appendChild(el('summary', '', '+ New tag'));
		var tagBox = el('div', 'ans-dash-newtag-box');
		var tagInput = document.createElement('input');
		tagInput.type = 'text';
		tagInput.maxLength = 100;
		tagInput.placeholder = 'Tag name…';
		tagInput.className = 'ans-dash-newtag-input';
		tagInput.setAttribute('aria-label', 'New tag name');
		var tagAdd = el('button', 'ans-dash-btn ans-dash-newtag-add', 'Add');
		tagAdd.type = 'button';
		function submitTag() {
			var name = tagInput.value.trim();
			if (name === '') {
				notify(cfg.i18n.tagRequired || 'Please enter a tag name.', true);
				return;
			}
			tagAdd.disabled = true;
			post('ans_dash_add_tag', { tag: name })
				.then(function (data) {
					state.customTags = data.tags || state.customTags;
					notify(cfg.i18n.tagAdded || 'Tag added.', false);
					render();
				})
				.catch(function (err) {
					tagAdd.disabled = false;
					notify(err.message, true);
				});
		}
		tagAdd.addEventListener('click', submitTag);
		tagInput.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				submitTag();
			}
		});
		tagBox.appendChild(tagInput);
		tagBox.appendChild(tagAdd);
		tagDetails.appendChild(tagBox);
		els.toolbar.appendChild(tagDetails);

		// Priority filter.
		var prioSel = el('select', 'ans-dash-filter-priority');
		prioSel.setAttribute('aria-label', 'Priority filter');
		prioSel.appendChild(option('', 'All priorities', state.filters.priority === ''));
		cfg.priorities.forEach(function (p) {
			prioSel.appendChild(option(p, p, state.filters.priority === p));
		});
		prioSel.addEventListener('change', function () {
			state.filters.priority = prioSel.value;
			schedulePrefsSave();
			render();
		});
		els.toolbar.appendChild(prioSel);

		// Search.
		var search = el('input', 'ans-dash-search');
		search.type = 'search';
		search.placeholder = 'Search tasks…';
		search.value = state.filters.search;
		search.setAttribute('aria-label', 'Search tasks');
		search.addEventListener('input', function () {
			state.filters.search = search.value;
			renderBody();
			renderSummary();
		});
		els.toolbar.appendChild(search);

		// Layout toggle (Kanban / List / Checklist).
		var layoutGroup = el('div', 'ans-dash-layout-group');
		layoutGroup.setAttribute('role', 'group');
		layoutGroup.setAttribute('aria-label', 'Layout');
		[['board', 'Kanban'], ['list', 'List'], ['checklist', 'Checklist']].forEach(function (pair) {
			var btn = el('button', 'ans-dash-btn ans-dash-layout-btn' + (state.layout === pair[0] ? ' is-on' : ''), pair[1]);
			btn.type = 'button';
			btn.setAttribute('aria-pressed', state.layout === pair[0] ? 'true' : 'false');
			btn.addEventListener('click', function () {
				if (state.layout === pair[0]) {
					return;
				}
				state.layout = pair[0];
				schedulePrefsSave();
				render();
			});
			layoutGroup.appendChild(btn);
		});
		els.toolbar.appendChild(layoutGroup);

		// "+ New Task" (write-back only).
		if (state.writeEnabled) {
			var newBtn = el('button', 'ans-dash-btn ans-dash-new-task', '+ New Task');
			newBtn.type = 'button';
			newBtn.addEventListener('click', function () {
				openNewTaskModal(newBtn);
			});
			els.toolbar.appendChild(newBtn);
		}

		// Column/field visibility.
		var details = el('details', 'ans-dash-columns');
		details.appendChild(el('summary', '', 'Fields'));
		var fieldsBox = el('div', 'ans-dash-columns-box');
		CARD_FIELDS.forEach(function (field) {
			var label = el('label', 'ans-dash-field-toggle');
			var cb = document.createElement('input');
			cb.type = 'checkbox';
			cb.checked = !fieldHidden(field);
			cb.addEventListener('change', function () {
				var idx = state.hiddenFields.indexOf(field);
				if (cb.checked && idx !== -1) {
					state.hiddenFields.splice(idx, 1);
				} else if (!cb.checked && idx === -1) {
					state.hiddenFields.push(field);
				}
				schedulePrefsSave();
				renderBody();
			});
			label.appendChild(cb);
			label.appendChild(document.createTextNode(' ' + (FIELD_LABELS[field] || field.charAt(0).toUpperCase() + field.slice(1))));
			fieldsBox.appendChild(label);
		});
		details.appendChild(fieldsBox);
		els.toolbar.appendChild(details);

		// Refresh.
		var refreshBtn = el('button', 'ans-dash-btn ans-dash-refresh', 'Refresh');
		refreshBtn.type = 'button';
		refreshBtn.addEventListener('click', function () {
			load(true);
		});
		els.toolbar.appendChild(refreshBtn);
	}

	/* ------------------------------------------------------------------ */
	/* Owner self-select banner                                            */
	/* ------------------------------------------------------------------ */

	function showOwnerBanner(force) {
		if (state.currentOwner && !force) {
			els.ownerBanner.hidden = true;
			return;
		}
		els.ownerBanner.hidden = false;
		els.ownerBanner.textContent = '';
		els.ownerBanner.appendChild(el('span', '', cfg.i18n.pickOwnerLead + ' '));

		var sel = el('select', 'ans-dash-owner-select');
		sel.appendChild(option('', 'Select…', true));
		cfg.owners.forEach(function (o) {
			sel.appendChild(option(o, o, false));
		});
		els.ownerBanner.appendChild(sel);

		var save = el('button', 'ans-dash-btn', 'Save');
		save.type = 'button';
		save.addEventListener('click', function () {
			if (!sel.value) {
				return;
			}
			post('ans_dash_set_owner', { owner: sel.value })
				.then(function (data) {
					state.currentOwner = data.owner;
					els.ownerBanner.hidden = true;
					notify(cfg.i18n.saved, false);
					render();
				})
				.catch(function (err) {
					notify(err.message, true);
				});
		});
		els.ownerBanner.appendChild(save);
	}

	/* ------------------------------------------------------------------ */
	/* Summary tiles                                                      */
	/* ------------------------------------------------------------------ */

	function renderSummary() {
		var visible = filteredTasks();
		els.summary.textContent = '';

		var open = 0;
		var overdue = 0;
		var dueSoon = 0;
		var byStatus = {};
		cfg.statuses.forEach(function (s) { byStatus[s] = 0; });

		visible.forEach(function (t) {
			if (byStatus.hasOwnProperty(t.Status)) {
				byStatus[t.Status] += 1;
			}
			if (t.Status !== 'Done') {
				open += 1;
			}
			if (isOverdue(t)) {
				overdue += 1;
			}
			if (isDueSoon(t)) {
				dueSoon += 1;
			}
		});

		function tile(label, value, extraClass) {
			var box = el('div', 'ans-dash-tile' + (extraClass ? ' ' + extraClass : ''));
			box.appendChild(el('span', 'ans-dash-tile-value', String(value)));
			box.appendChild(el('span', 'ans-dash-tile-label', label));
			els.summary.appendChild(box);
		}

		tile('Open', open, 'is-open');
		tile('Overdue', overdue, overdue > 0 ? 'is-overdue' : '');
		tile('Due in 7 days', dueSoon, dueSoon > 0 ? 'is-duesoon' : '');
		cfg.statuses.forEach(function (s) {
			tile(s, byStatus[s], 'is-status-' + s.toLowerCase().replace(/\s+/g, '-'));
		});
	}

	/* ------------------------------------------------------------------ */
	/* Cards                                                              */
	/* ------------------------------------------------------------------ */

	function buildCard(task) {
		var card = el('article', 'ans-dash-card');
		card.dataset.taskId = task['Task ID'];
		card.setAttribute('tabindex', '0');

		var head = el('header', 'ans-dash-card-head');
		var idWrap = el('span', 'ans-dash-card-id-wrap');
		idWrap.appendChild(buildDot(task));
		idWrap.appendChild(el('span', 'ans-dash-card-id', task['Task ID']));
		head.appendChild(idWrap);
		if (!fieldHidden('priority') && task.Priority) {
			head.appendChild(el('span', 'ans-dash-priority ans-dash-priority-' + task.Priority.toLowerCase(), task.Priority));
		}
		card.appendChild(head);

		card.appendChild(el('p', 'ans-dash-card-task', task.Task));

		var meta = el('div', 'ans-dash-card-meta');
		if (!fieldHidden('owner')) {
			if (state.writeEnabled) {
				var sel = el('select', 'ans-dash-card-owner-select');
				sel.setAttribute('aria-label', 'Owner for ' + task['Task ID']);
				sel.appendChild(option('', 'Unassigned', task.Owner === ''));
				cfg.owners.forEach(function (o) {
					sel.appendChild(option(o, o, task.Owner === o));
				});
				sel.addEventListener('change', function () {
					updateTask(task['Task ID'], 'Owner', sel.value, function revert() {
						sel.value = task.Owner;
					});
				});
				meta.appendChild(sel);
			} else if (task.Owner) {
				meta.appendChild(el('span', 'ans-dash-chip ans-dash-chip-owner', task.Owner));
			}
		}
		if (!fieldHidden('due') && task.Due) {
			meta.appendChild(el('span', 'ans-dash-chip ans-dash-chip-due' + (isOverdue(task) ? ' is-overdue' : ''), 'Due ' + task.Due));
		}
		if (!fieldHidden('bucket') && task.Bucket) {
			meta.appendChild(el('span', 'ans-dash-chip ans-dash-chip-bucket', task.Bucket));
		}
		if (!fieldHidden('event') && task['Concert/Event']) {
			meta.appendChild(el('span', 'ans-dash-chip ans-dash-chip-event', task['Concert/Event']));
		}
		if (task['Added By']) {
			var addedChip = el('span', 'ans-dash-chip ans-dash-chip-addedby', task['Added By']);
			addedChip.title = 'Added by ' + task['Added By'];
			meta.appendChild(addedChip);
		}
		meta.appendChild(buildClaudeRowButton(task));
		if (meta.childNodes.length) {
			card.appendChild(meta);
		}

		var cardMeter = buildProgressMeter(task);
		if (cardMeter) {
			card.appendChild(cardMeter);
		}

		if (state.writeEnabled) {
			card.draggable = true;
			card.addEventListener('dragstart', function (e) {
				e.dataTransfer.setData('text/plain', task['Task ID']);
				e.dataTransfer.effectAllowed = 'move';
				card.classList.add('is-dragging');
			});
			card.addEventListener('dragend', function () {
				card.classList.remove('is-dragging');
			});
		}

		return card;
	}

	/* ------------------------------------------------------------------ */
	/* Board layout                                                       */
	/* ------------------------------------------------------------------ */

	function renderBoard(tasks) {
		var board = el('div', 'ans-dash-board');
		cfg.statuses.forEach(function (status) {
			var colTasks = tasks.filter(function (t) { return t.Status === status; });
			var col = el('section', 'ans-dash-col ans-dash-col-' + status.toLowerCase().replace(/\s+/g, '-'));
			col.dataset.status = status;

			var head = el('header', 'ans-dash-col-head');
			head.appendChild(el('h3', 'ans-dash-col-title', status));
			head.appendChild(el('span', 'ans-dash-col-count', String(colTasks.length)));
			col.appendChild(head);

			var list = el('div', 'ans-dash-col-cards');
			colTasks.forEach(function (t) {
				list.appendChild(buildCard(t));
			});
			col.appendChild(list);

			if (state.writeEnabled) {
				col.addEventListener('dragover', function (e) {
					e.preventDefault();
					e.dataTransfer.dropEffect = 'move';
					col.classList.add('is-drop-target');
				});
				col.addEventListener('dragleave', function () {
					col.classList.remove('is-drop-target');
				});
				col.addEventListener('drop', function (e) {
					e.preventDefault();
					col.classList.remove('is-drop-target');
					var taskId = e.dataTransfer.getData('text/plain');
					var task = state.tasks.find(function (t) { return t['Task ID'] === taskId; });
					if (!task || task.Status === status) {
						return;
					}
					var previous = task.Status;
					task.Status = status; // Optimistic move.
					renderBody();
					renderSummary();
					updateTask(taskId, 'Status', status, function revert() {
						task.Status = previous;
						renderBody();
						renderSummary();
					});
				});
			}

			board.appendChild(col);
		});
		return board;
	}

	/* ------------------------------------------------------------------ */
	/* List layout (grouped by bucket, collapsible)                        */
	/* ------------------------------------------------------------------ */

	function renderList(tasks) {
		var wrap = el('div', 'ans-dash-list');
		var groups = {};
		var order = [];
		tasks.forEach(function (t) {
			var key = t.Bucket || '(No tag)';
			if (!groups[key]) {
				groups[key] = [];
				order.push(key);
			}
			groups[key].push(t);
		});
		order.sort(function (a, b) { return a.localeCompare(b); });

		order.forEach(function (bucket) {
			var collapsed = state.collapsedBuckets.indexOf(bucket) !== -1;
			var group = el('section', 'ans-dash-list-group' + (collapsed ? ' is-collapsed' : ''));

			var head = el('button', 'ans-dash-list-group-head');
			head.type = 'button';
			head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
			head.appendChild(el('span', 'ans-dash-list-group-caret', collapsed ? '▸' : '▾'));
			head.appendChild(el('span', 'ans-dash-list-group-title', bucket));
			head.appendChild(el('span', 'ans-dash-col-count', String(groups[bucket].length)));
			head.addEventListener('click', function () {
				var idx = state.collapsedBuckets.indexOf(bucket);
				if (idx === -1) {
					state.collapsedBuckets.push(bucket);
				} else {
					state.collapsedBuckets.splice(idx, 1);
				}
				schedulePrefsSave();
				renderBody();
			});
			group.appendChild(head);

			if (!collapsed) {
				var table = el('table', 'ans-dash-table');
				var thead = document.createElement('thead');
				var hrow = document.createElement('tr');
				var headers = ['ID', 'Task', 'Status'];
				if (!fieldHidden('owner')) { headers.push('Owner'); }
				if (!fieldHidden('priority')) { headers.push('Priority'); }
				if (!fieldHidden('due')) { headers.push('Due'); }
				if (!fieldHidden('event')) { headers.push('Concert/Event'); }
				headers.forEach(function (h) {
					hrow.appendChild(el('th', '', h));
				});
				thead.appendChild(hrow);
				table.appendChild(thead);

				var tbody = document.createElement('tbody');
				groups[bucket].forEach(function (t) {
					var tr = document.createElement('tr');
					tr.appendChild(el('td', 'ans-dash-td-id', t['Task ID']));
					tr.appendChild(el('td', 'ans-dash-td-task', t.Task));
					tr.appendChild(el('td', 'ans-dash-td-status', t.Status));
					if (!fieldHidden('owner')) {
						tr.appendChild(el('td', '', t.Owner));
					}
					if (!fieldHidden('priority')) {
						var tdP = el('td', '');
						if (t.Priority) {
							tdP.appendChild(el('span', 'ans-dash-priority ans-dash-priority-' + t.Priority.toLowerCase(), t.Priority));
						}
						tr.appendChild(tdP);
					}
					if (!fieldHidden('due')) {
						tr.appendChild(el('td', isOverdue(t) ? 'is-overdue' : '', t.Due));
					}
					if (!fieldHidden('event')) {
						tr.appendChild(el('td', '', t['Concert/Event']));
					}
					tbody.appendChild(tr);
				});
				table.appendChild(tbody);
				group.appendChild(table);
			}

			wrap.appendChild(group);
		});

		return wrap;
	}

	/* ------------------------------------------------------------------ */
	/* Checklist layout (two columns: Open | Done, big checkboxes)         */
	/* ------------------------------------------------------------------ */

	function renderChecklist(tasks) {
		var wrap = el('div', 'ans-dash-checklist');

		var openTasks = tasks.filter(function (t) { return t.Status !== 'Done'; }).sort(openOrderCompare);
		var doneTasks = tasks.filter(function (t) { return t.Status === 'Done'; });

		// Single-level subtask nesting for the Open column: group children
		// under a visible parent; anything whose parent isn't a visible
		// top-level task falls back to top level.
		var byId = {};
		openTasks.forEach(function (t) { byId[t['Task ID']] = t; });

		var top = [];
		var children = {};
		openTasks.forEach(function (t) {
			var p = String(t['Parent Task ID'] || '').trim();
			if (p && p !== t['Task ID'] && byId[p]) {
				if (!children[p]) {
					children[p] = [];
				}
				children[p].push(t);
			} else {
				top.push(t);
			}
		});
		// Promote children whose parent is itself a child (or in a cycle):
		// only one level of visual nesting.
		var topIds = {};
		top.forEach(function (t) { topIds[t['Task ID']] = true; });
		Object.keys(children).forEach(function (pid) {
			if (!topIds[pid]) {
				children[pid].forEach(function (c) { top.push(c); });
				delete children[pid];
			}
		});
		top.sort(openOrderCompare);

		function column(title, count, extraClass, emptyText, fill) {
			var col = el('section', 'ans-dash-check-col ' + extraClass);

			var head = el('header', 'ans-dash-col-head');
			head.appendChild(el('h3', 'ans-dash-col-title', title));
			head.appendChild(el('span', 'ans-dash-col-count', String(count)));
			col.appendChild(head);

			if (count) {
				var listEl = el('ul', 'ans-dash-check-rows');
				fill(listEl);
				col.appendChild(listEl);
			} else {
				col.appendChild(el('p', 'ans-dash-empty', emptyText));
			}

			wrap.appendChild(col);
		}

		column('Open', openTasks.length, 'ans-dash-check-col-open', cfg.i18n.openEmpty, function (listEl) {
			top.forEach(function (t) {
				listEl.appendChild(buildCheckRow(t, { open: true }));
				(children[t['Task ID']] || []).forEach(function (c) {
					listEl.appendChild(buildCheckRow(c, { open: true, sub: true }));
				});
			});
		});
		column('Done', doneTasks.length, 'ans-dash-check-col-done', cfg.i18n.doneEmpty, function (listEl) {
			doneTasks.forEach(function (t) {
				listEl.appendChild(buildCheckRow(t, { open: false }));
			});
		});

		return wrap;
	}

	function toggleCheckTask(task, cb) {
		var taskId = task['Task ID'];
		var title = task.Task;
		var previous = task.Status;
		var next = cb.checked ? 'Done' : 'To Do';
		task.Status = next; // Optimistic, like the drag write-back.
		renderBody();
		renderSummary();
		updateTask(taskId, 'Status', next, function revert() {
			var t = findTask(taskId);
			if (t) {
				t.Status = previous;
			}
			renderBody();
			renderSummary();
		}, function onSuccess() {
			// Named toast + Undo: solves "which task did I just check?".
			var msg = next === 'Done'
				? fmt(cfg.i18n.markedDone || 'Marked "%s" as Done', title)
				: fmt(cfg.i18n.markedOpen || 'Marked "%s" as not done', title);
			showUndo(msg, function undo() {
				var t = findTask(taskId);
				if (!t) {
					return;
				}
				var was = t.Status;
				t.Status = previous; // Optimistic revert.
				renderBody();
				renderSummary();
				updateTask(taskId, 'Status', previous, function revert() {
					var t2 = findTask(taskId);
					if (t2) {
						t2.Status = was;
					}
					renderBody();
					renderSummary();
				}, function () {
					notify(cfg.i18n.undone || 'Change undone.', false);
				});
			});
		});
	}

	function wireRowDrag(row, listGetter) {
		row.draggable = true;
		row.addEventListener('dragstart', function (e) {
			draggingRow = row;
			e.dataTransfer.setData('text/plain', 'ans-order:' + row.dataset.taskId);
			e.dataTransfer.effectAllowed = 'move';
			row.classList.add('is-dragging');
		});
		row.addEventListener('dragend', function () {
			row.classList.remove('is-dragging');
			draggingRow = null;
			var list = listGetter();
			if (list) {
				Array.prototype.forEach.call(list.querySelectorAll('.is-drop-before, .is-drop-after'), function (r) {
					r.classList.remove('is-drop-before', 'is-drop-after');
				});
			}
		});
		row.addEventListener('dragover', function (e) {
			if (!draggingRow || draggingRow === row) {
				return;
			}
			e.preventDefault();
			e.dataTransfer.dropEffect = 'move';
			var rect = row.getBoundingClientRect();
			var before = e.clientY < rect.top + rect.height / 2;
			row.classList.toggle('is-drop-before', before);
			row.classList.toggle('is-drop-after', !before);
		});
		row.addEventListener('dragleave', function () {
			row.classList.remove('is-drop-before', 'is-drop-after');
		});
		row.addEventListener('drop', function (e) {
			if (!draggingRow || draggingRow === row) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			var before = row.classList.contains('is-drop-before');
			row.classList.remove('is-drop-before', 'is-drop-after');
			row.parentNode.insertBefore(draggingRow, before ? row : row.nextSibling);
			var list = row.parentNode;
			var ids = Array.prototype.map.call(list.querySelectorAll('.ans-dash-check-row'), function (r) {
				return r.dataset.taskId;
			});
			persistOrder(ids);
			renderBody(); // Re-sort + re-nest from the saved order.
		});
	}

	function buildCheckRow(task, opts) {
		opts = opts || {};
		var done = task.Status === 'Done';
		var row = el('li', 'ans-dash-check-row' + (done ? ' is-done' : '') + (opts.sub ? ' is-subtask' : ''));
		row.dataset.taskId = task['Task ID'];

		var cb = document.createElement('input');
		cb.type = 'checkbox';
		cb.className = 'ans-dash-check-box';
		cb.checked = done;
		cb.setAttribute('aria-label', task['Task ID'] + ': ' + task.Task);
		if (!state.writeEnabled) {
			// Read-only mode: show state but never silently no-op.
			cb.disabled = true;
			cb.title = cfg.i18n.writeOff;
		} else {
			cb.addEventListener('change', function () {
				toggleCheckTask(task, cb);
			});
		}
		row.appendChild(cb);

		var main = el('div', 'ans-dash-check-main');

		// Clicking the task TEXT opens the detail modal (never toggles the box).
		var taskText = el('span', 'ans-dash-check-task is-clickable', task.Task);
		taskText.setAttribute('role', 'button');
		taskText.tabIndex = 0;
		taskText.title = 'Open details for ' + task['Task ID'];
		taskText.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			openTaskDetailModal(task['Task ID'], taskText);
		});
		taskText.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				openTaskDetailModal(task['Task ID'], taskText);
			}
		});
		main.appendChild(taskText);

		var meta = el('span', 'ans-dash-check-meta');
		if (task.Owner) {
			meta.appendChild(el('span', 'ans-dash-check-owner', task.Owner));
		}
		if (task.Due) {
			meta.appendChild(el('span', 'ans-dash-check-due' + (isOverdue(task) ? ' is-overdue' : ''), 'Due ' + task.Due));
		}
		meta.appendChild(buildDot(task));
		if (task.Bucket) {
			meta.appendChild(el('span', 'ans-dash-chip ans-dash-chip-bucket', task.Bucket));
		}
		if (task['Added By']) {
			var addedChip = el('span', 'ans-dash-chip ans-dash-chip-addedby', task['Added By']);
			addedChip.title = 'Added by ' + task['Added By'];
			meta.appendChild(addedChip);
		}
		if (done && task['Done At']) {
			meta.appendChild(el('span', 'ans-dash-check-doneat', formatDoneAt(task['Done At'])));
		}
		meta.appendChild(buildClaudeRowButton(task));
		if (meta.childNodes.length) {
			main.appendChild(meta);
		}

		// Progress meter under the task text (only when there's progress data).
		var rowMeter = buildProgressMeter(task);
		if (rowMeter) {
			rowMeter.classList.add('ans-dash-progress-row');
			main.appendChild(rowMeter);
		}
		row.appendChild(main);

		// Per-person drag-order in the Open column. Ordering is user-local
		// (user meta), so it works regardless of the sheet write flag.
		if (opts.open) {
			wireRowDrag(row, function () { return row.parentNode; });
		}

		return row;
	}

	/* ------------------------------------------------------------------ */
	/* "+ New Task" modal                                                  */
	/* ------------------------------------------------------------------ */

	function formRow(labelText, control) {
		var rowEl = el('label', 'ans-dash-form-row');
		rowEl.appendChild(el('span', 'ans-dash-form-label', labelText));
		rowEl.appendChild(control);
		return rowEl;
	}

	function datalistFor(idSuffix, values) {
		var dl = document.createElement('datalist');
		dl.id = 'ans-dash-dl-' + idSuffix;
		values.forEach(function (v) {
			var o = document.createElement('option');
			o.value = v;
			dl.appendChild(o);
		});
		return dl;
	}

	function openNewTaskModal(opener) {
		var overlay = el('div', 'ans-dash-modal-overlay');
		var modal = el('div', 'ans-dash-modal');
		modal.setAttribute('role', 'dialog');
		modal.setAttribute('aria-modal', 'true');
		modal.setAttribute('aria-labelledby', 'ans-dash-modal-title');

		var title = el('h2', 'ans-dash-modal-title', 'New Task');
		title.id = 'ans-dash-modal-title';
		modal.appendChild(title);

		var form = el('form', 'ans-dash-modal-form');
		var errorBox = el('p', 'ans-dash-form-error');
		errorBox.hidden = true;
		errorBox.setAttribute('role', 'alert');

		// Task (required).
		var taskInput = document.createElement('input');
		taskInput.type = 'text';
		taskInput.maxLength = 300;
		taskInput.required = true;
		taskInput.className = 'ans-dash-form-input';
		form.appendChild(formRow('Task *', taskInput));

		// Owner.
		var ownerSel = el('select', 'ans-dash-form-input');
		ownerSel.appendChild(option('', '—', true));
		cfg.owners.forEach(function (o) {
			ownerSel.appendChild(option(o, o, false));
		});
		form.appendChild(formRow('Owner', ownerSel));

		// Tag (text + datalist of known + custom tags; sheet column: Bucket).
		var bucketInput = document.createElement('input');
		bucketInput.type = 'text';
		bucketInput.maxLength = 100;
		bucketInput.className = 'ans-dash-form-input';
		bucketInput.setAttribute('list', 'ans-dash-dl-buckets');
		form.appendChild(formRow('Tag', bucketInput));
		form.appendChild(datalistFor('buckets', tagChoices()));

		// Priority.
		var prioSel = el('select', 'ans-dash-form-input');
		prioSel.appendChild(option('', '—', true));
		cfg.priorities.forEach(function (p) {
			prioSel.appendChild(option(p, p, false));
		});
		form.appendChild(formRow('Priority', prioSel));

		// Due date.
		var dueInput = document.createElement('input');
		dueInput.type = 'date';
		dueInput.className = 'ans-dash-form-input';
		form.appendChild(formRow('Due', dueInput));

		// Concert/Event (text + datalist of known values).
		var eventInput = document.createElement('input');
		eventInput.type = 'text';
		eventInput.maxLength = 100;
		eventInput.className = 'ans-dash-form-input';
		eventInput.setAttribute('list', 'ans-dash-dl-events');
		form.appendChild(formRow('Concert/Event', eventInput));
		form.appendChild(datalistFor('events', distinctValues('Concert/Event')));

		form.appendChild(errorBox);

		var actions = el('div', 'ans-dash-modal-actions');
		var cancelBtn = el('button', 'ans-dash-btn', 'Cancel');
		cancelBtn.type = 'button';
		var submitBtn = el('button', 'ans-dash-btn ans-dash-btn-primary', 'Add task');
		submitBtn.type = 'submit';
		actions.appendChild(cancelBtn);
		actions.appendChild(submitBtn);
		form.appendChild(actions);
		modal.appendChild(form);
		overlay.appendChild(modal);
		root.appendChild(overlay);

		function close() {
			document.removeEventListener('keydown', onKeydown);
			overlay.remove();
			if (opener && typeof opener.focus === 'function') {
				opener.focus();
			}
		}

		function onKeydown(e) {
			if (e.key === 'Escape') {
				close();
			}
		}
		document.addEventListener('keydown', onKeydown);
		cancelBtn.addEventListener('click', close);
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) {
				close();
			}
		});

		function showError(message) {
			errorBox.hidden = false;
			errorBox.textContent = message;
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			errorBox.hidden = true;
			if (taskInput.value.trim() === '') {
				showError(cfg.i18n.taskRequired);
				taskInput.focus();
				return;
			}
			submitBtn.disabled = true;
			post('ans_dash_create_task', {
				task: taskInput.value.trim(),
				owner: ownerSel.value,
				bucket: bucketInput.value.trim(),
				priority: prioSel.value,
				due: dueInput.value,
				event: eventInput.value.trim()
			})
				.then(function (data) {
					// Insert the server-authoritative row into the current view.
					state.tasks.push(data.task);
					if (data.today) {
						state.today = data.today;
					}
					close();
					notify(cfg.i18n.taskCreated, false);
					render();
				})
				.catch(function (err) {
					submitBtn.disabled = false;
					showError(err.message);
				});
		});

		taskInput.focus();
	}

	/* ------------------------------------------------------------------ */
	/* Task detail modal                                                   */
	/* ------------------------------------------------------------------ */

	function openTaskDetailModal(taskId, opener) {
		var task = findTask(taskId);
		if (!task) {
			return;
		}

		var overlay = el('div', 'ans-dash-modal-overlay');
		var modal = el('div', 'ans-dash-modal ans-dash-detail-modal');
		modal.setAttribute('role', 'dialog');
		modal.setAttribute('aria-modal', 'true');
		modal.setAttribute('aria-labelledby', 'ans-dash-detail-title');

		var head = el('div', 'ans-dash-detail-head');
		head.appendChild(el('span', 'ans-dash-card-id', task['Task ID']));
		if (task['Added By']) {
			var addedChip = el('span', 'ans-dash-chip ans-dash-chip-addedby', task['Added By']);
			addedChip.title = 'Added by ' + task['Added By'];
			head.appendChild(addedChip);
		}
		modal.appendChild(head);

		var title = el('h2', 'ans-dash-modal-title', task.Task);
		title.id = 'ans-dash-detail-title';
		modal.appendChild(title);

		// Progress meter, prominent near the top (only with progress data).
		var detailMeter = buildProgressMeter(task);
		if (detailMeter) {
			detailMeter.classList.add('ans-dash-progress-detail');
			modal.appendChild(detailMeter);
		}

		// "Send to Claude Desktop" (claude:// deep link) + web fallback.
		var claudeWrap = el('div', 'ans-dash-claude-send');
		var claudeBtn = el('button', 'ans-dash-btn ans-dash-btn-primary ans-dash-claude-primary', 'Send to Claude Desktop');
		claudeBtn.type = 'button';
		claudeBtn.title = 'Open Claude Desktop with a pre-filled brief for ' + task['Task ID'];
		claudeBtn.addEventListener('click', function () {
			sendTaskToClaude(task['Task ID']);
		});
		claudeWrap.appendChild(claudeBtn);
		var claudeWebLink = document.createElement('a');
		claudeWebLink.className = 'ans-dash-claude-web';
		claudeWebLink.href = claudeWebUrl(task);
		claudeWebLink.textContent = 'open in web instead';
		claudeWebLink.target = '_blank';
		claudeWebLink.rel = 'noopener noreferrer';
		claudeWrap.appendChild(claudeWebLink);
		modal.appendChild(claudeWrap);

		// Read-only meta grid: Added By, Owner, Due, Priority, Tag.
		var metaGrid = el('dl', 'ans-dash-detail-meta');
		function metaRow(label, value) {
			metaGrid.appendChild(el('dt', 'ans-dash-detail-meta-label', label));
			metaGrid.appendChild(el('dd', 'ans-dash-detail-meta-value', value || '—'));
		}
		metaRow('Added by', task['Added By']);
		metaRow('Owner', task.Owner);
		metaRow('Due', task.Due);
		metaRow('Priority', task.Priority);
		modal.appendChild(metaGrid);

		// Read-only step checklist from the Steps column (v1.3.0; the
		// ans-task-runner skill maintains it — no toggling here).
		var steps = stepLines(task);
		if (steps.length) {
			var prog = progressOf(task);
			modal.appendChild(el('h3', 'ans-dash-steps-title', 'Steps (' + prog.done + '/' + prog.total + ')'));
			var stepsList = el('ul', 'ans-dash-steps');
			steps.forEach(function (line) {
				var stepDone = STEP_DONE_RE.test(line);
				var li = el('li', 'ans-dash-step' + (stepDone ? ' is-done' : ''));
				li.appendChild(el('span', 'ans-dash-step-mark', stepDone ? '✓' : '○'));
				li.appendChild(el('span', 'ans-dash-step-text', line.replace(/^\s*\[[ xX]\]\s*/, '')));
				stepsList.appendChild(li);
			});
			modal.appendChild(stepsList);
		}

		var form = el('form', 'ans-dash-modal-form');
		var errorBox = el('p', 'ans-dash-form-error');
		errorBox.hidden = true;
		errorBox.setAttribute('role', 'alert');

		// Tag (sheet column: Bucket), editable, with the merged tag choices.
		var tagInput = document.createElement('input');
		tagInput.type = 'text';
		tagInput.maxLength = 100;
		tagInput.className = 'ans-dash-form-input';
		tagInput.value = task.Bucket || '';
		tagInput.setAttribute('list', 'ans-dash-dl-detail-tags');
		form.appendChild(formRow('Tag', tagInput));
		form.appendChild(datalistFor('detail-tags', tagChoices()));

		// Notes (column J), editable.
		var notesInput = document.createElement('textarea');
		notesInput.className = 'ans-dash-form-input ans-dash-detail-notes';
		notesInput.rows = 4;
		notesInput.maxLength = 5000;
		notesInput.value = task.Notes || '';
		form.appendChild(formRow('Notes', notesInput));

		// Links (column Q), one URL per line, rendered as clickable links.
		var linksInput = document.createElement('textarea');
		linksInput.className = 'ans-dash-form-input ans-dash-detail-links';
		linksInput.rows = 3;
		linksInput.maxLength = 2000;
		linksInput.placeholder = 'One URL per line…';
		linksInput.value = task.Links || '';
		form.appendChild(formRow('Links (one URL per line)', linksInput));

		var linkList = el('div', 'ans-dash-link-list');
		function renderLinkList() {
			linkList.textContent = '';
			String(linksInput.value || '').split(/\r\n|\r|\n/).forEach(function (line) {
				line = line.trim();
				if (line === '' || !/^https?:\/\//i.test(line)) {
					return;
				}
				var a = document.createElement('a');
				a.className = 'ans-dash-link';
				a.href = line;
				a.textContent = line;
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				linkList.appendChild(a);
			});
		}
		renderLinkList();
		linksInput.addEventListener('input', renderLinkList);
		form.appendChild(linkList);

		// Parent task (column P): single-level subtask hierarchy.
		var parentSel = el('select', 'ans-dash-form-input ans-dash-detail-parent');
		var currentParent = String(task['Parent Task ID'] || '').trim();
		parentSel.appendChild(option('', cfg.i18n.noParent || '— none —', currentParent === ''));
		state.tasks.forEach(function (t) {
			if (t['Task ID'] === task['Task ID']) {
				return; // Never offer the task as its own parent.
			}
			var label = t['Task ID'] + ' — ' + (t.Task.length > 60 ? t.Task.slice(0, 60) + '…' : t.Task);
			parentSel.appendChild(option(t['Task ID'], label, currentParent === t['Task ID']));
		});
		// A stored parent that no longer exists is ignored (shows "— none —").
		if (currentParent !== '' && !findTask(currentParent)) {
			parentSel.value = '';
		}
		form.appendChild(formRow('Parent task', parentSel));

		form.appendChild(errorBox);

		var actions = el('div', 'ans-dash-modal-actions');
		var closeBtn = el('button', 'ans-dash-btn', 'Close');
		closeBtn.type = 'button';
		actions.appendChild(closeBtn);

		var saveBtn = null;
		if (state.writeEnabled) {
			saveBtn = el('button', 'ans-dash-btn ans-dash-btn-primary', 'Save');
			saveBtn.type = 'submit';
			actions.appendChild(saveBtn);
		} else {
			tagInput.disabled = true;
			notesInput.disabled = true;
			linksInput.disabled = true;
			parentSel.disabled = true;
			tagInput.title = cfg.i18n.writeOff;
			notesInput.title = cfg.i18n.writeOff;
			linksInput.title = cfg.i18n.writeOff;
			parentSel.title = cfg.i18n.writeOff;
		}
		form.appendChild(actions);
		modal.appendChild(form);
		overlay.appendChild(modal);
		root.appendChild(overlay);

		function close() {
			document.removeEventListener('keydown', onKeydown);
			overlay.remove();
			if (opener && typeof opener.focus === 'function') {
				opener.focus();
			}
		}

		function onKeydown(e) {
			if (e.key === 'Escape') {
				close();
			}
		}
		document.addEventListener('keydown', onKeydown);
		closeBtn.addEventListener('click', close);
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) {
				close();
			}
		});

		function showError(message) {
			errorBox.hidden = false;
			errorBox.textContent = message;
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!state.writeEnabled) {
				return;
			}
			errorBox.hidden = true;

			var fresh = findTask(taskId) || task;
			var changes = [];
			if (tagInput.value.trim() !== (fresh.Bucket || '')) {
				changes.push(['Bucket', tagInput.value.trim()]);
			}
			if (notesInput.value !== (fresh.Notes || '')) {
				changes.push(['Notes', notesInput.value]);
			}
			if (linksInput.value !== (fresh.Links || '')) {
				changes.push(['Links', linksInput.value]);
			}
			var newParent = parentSel.value;
			if (newParent === taskId) {
				newParent = ''; // Belt & braces: never self-parent.
			}
			if (newParent !== '' && !findTask(newParent)) {
				newParent = ''; // Ignore a parent that doesn't exist.
			}
			if (newParent !== currentParent) {
				changes.push(['Parent Task ID', newParent]);
			}
			if (!changes.length) {
				close();
				return;
			}

			saveBtn.disabled = true;
			var chain = Promise.resolve();
			changes.forEach(function (pair) {
				chain = chain.then(function () {
					return post('ans_dash_update_task', { task_id: taskId, field: pair[0], value: pair[1] })
						.then(function (data) {
							var idx = state.tasks.findIndex(function (t) { return t['Task ID'] === taskId; });
							if (idx !== -1) {
								state.tasks[idx] = data.task;
							}
							if (data.today) {
								state.today = data.today;
							}
						});
				});
			});
			chain
				.then(function () {
					close();
					notify(cfg.i18n.saved, false);
					renderBody();
					renderSummary();
				})
				.catch(function (err) {
					saveBtn.disabled = false;
					showError(err.message);
					renderBody();
					renderSummary();
				});
		});

		(state.writeEnabled ? notesInput : closeBtn).focus();
	}

	/* ------------------------------------------------------------------ */
	/* Write-back                                                          */
	/* ------------------------------------------------------------------ */

	function updateTask(taskId, field, value, revert, onSuccess) {
		post('ans_dash_update_task', { task_id: taskId, field: field, value: value })
			.then(function (data) {
				// Replace the local copy with the authoritative row.
				var idx = state.tasks.findIndex(function (t) { return t['Task ID'] === taskId; });
				if (idx !== -1) {
					state.tasks[idx] = data.task;
				}
				if (data.today) {
					state.today = data.today;
				}
				if (typeof onSuccess === 'function') {
					onSuccess(data);
				} else {
					notify(cfg.i18n.saved, false);
				}
				renderBody();
				renderSummary();
			})
			.catch(function (err) {
				if (typeof revert === 'function') {
					revert();
				}
				notify(err.message, true);
			});
	}

	/* ------------------------------------------------------------------ */
	/* Render + load                                                       */
	/* ------------------------------------------------------------------ */

	function renderBody() {
		closeColorPopover(); // Its anchor is about to be re-rendered away.
		var tasks = filteredTasks();
		els.body.textContent = '';
		if (!tasks.length) {
			els.body.appendChild(el('p', 'ans-dash-empty', cfg.i18n.empty));
			return;
		}
		if (state.layout === 'checklist') {
			els.body.appendChild(renderChecklist(tasks));
		} else if (state.layout === 'list') {
			els.body.appendChild(renderList(tasks));
		} else {
			els.body.appendChild(renderBoard(tasks));
		}
	}

	function render() {
		renderToolbar();
		renderSummary();
		renderBody();
		showOwnerBanner(false);
	}

	function load(refresh) {
		els.body.textContent = '';
		els.body.appendChild(el('p', 'ans-dash-loading', cfg.i18n.loading));
		post('ans_dash_fetch', refresh ? { refresh: '1' } : {})
			.then(function (data) {
				state.tasks = data.tasks || [];
				state.today = data.today || '';
				state.fetchedAt = data.fetchedAt || 0;
				state.writeEnabled = !!data.writeEnabled;
				render();
				if (refresh) {
					notify(cfg.i18n.refreshed, false);
				}
			})
			.catch(function (err) {
				els.body.textContent = '';
				var msg = el('p', 'ans-dash-error', cfg.i18n.loadError + ' ' + err.message);
				els.body.appendChild(msg);
			});
	}

	load(false);
})();
