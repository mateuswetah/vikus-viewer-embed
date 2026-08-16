/**
 * WordPress-specific Vikus Viewer compatibility layer.
 *
 * Keeps vendor-vikus pristine: patches run after the upstream scripts load.
 */
(function () {
	'use strict';

	function nonEmpty(value) {
		return value !== undefined && value !== null && String(value).trim() !== '';
	}

	function patchDetailVue() {
		if (!window.detailVue) {
			return;
		}

		var originalGetContent = detailVue.getContent;

		// Upstream getContent assumes array/keywords are JS arrays (.join) and
		// evals type "function". WP CSV cells are often strings; skip eval.
		detailVue.getContent = function (entry) {
			if (!this.item || !entry) {
				return '';
			}
			if (entry.type === 'function') {
				return '';
			}
			if (entry.type === 'array' || entry.type === 'keywords') {
				var value = this.item[entry.source];
				if (Array.isArray(value)) {
					return value.join(', ');
				}
				return value == null ? '' : value;
			}
			if (entry.type === 'markdown' && !nonEmpty(this.item[entry.source])) {
				return '';
			}
			var out = originalGetContent.call(this, entry);
			return out == null ? '' : out;
		};

		// Upstream hasData is getContent() !== ''. type "link" is rendered from
		// item[source] in the template, and getContent returns undefined.
		detailVue.hasData = function (entry) {
			if (!this.item || !entry) {
				return false;
			}
			if (entry.type === 'link') {
				return nonEmpty(this.item[entry.source]);
			}
			return nonEmpty(this.getContent(entry));
		};
	}

	function ensureDetailStructure() {
		if (typeof config === 'undefined' || !config) {
			return;
		}
		if (!config.detail) {
			config.detail = {};
		}
		if (!Array.isArray(config.detail.structure) || !config.detail.structure.length) {
			config.detail.structure = [
				{ name: 'Title', source: '_title', type: 'text', display: 'column' },
				{ name: 'Year', source: '_year', type: 'text', display: 'column' },
				{ name: 'Keywords', source: '_keywords', type: 'keywords', display: 'wide' },
				{ name: 'Permalink', source: '_permalink', type: 'link', display: 'wide' }
			];
		}
		if (window.detailVue) {
			detailVue.structure = config.detail.structure;
		}
	}

	function enrichDetailItem() {
		if (!window.detailVue || !detailVue.item) {
			return;
		}
		var item = detailVue.item;
		if (!item._title) {
			item._title = item.title || item.name || item._id || item.id || '';
		}
		if (!item._permalink) {
			item._permalink = item.permalink || item.url || '';
		}
		if (!item._keywords) {
			item._keywords = item.keywords || 'None';
		}
		if (item._year == null || item._year === '') {
			item._year = item.year || '';
		}
	}

	function patchShowDetail() {
		if (!window.canvas || typeof canvas.showDetail !== 'function') {
			return false;
		}
		if (canvas.showDetail._vikusWp) {
			return true;
		}

		var original = canvas.showDetail;
		canvas.showDetail = function (d) {
			ensureDetailStructure();
			original.call(canvas, d);

			// Upstream marks iframe embeds as "sneak"; WP shortcode/block embeds
			// should only collapse on mobile.
			var mobile = window.utils && typeof utils.isMobile === 'function' && utils.isMobile();
			if (window.d3) {
				d3.select('.sidebar.detail').classed('sneak', !!mobile);
			}

			enrichDetailItem();
		};
		canvas.showDetail._vikusWp = true;
		return true;
	}

	function isUsableGroupKey( key ) {
		return key != null && String( key ).trim() !== '' && String( key ) !== 'undefined';
	}

	/**
	 * Extra group layouts nest timeline.csv rows that lack that groupKey.
	 * d3.nest then stringifies the missing value as "undefined". Wrap upstream
	 * (do not reimplement — Canvas locals are closed over) and drop bad keys.
	 */
	function patchInitGroupLayout() {
		if (!window.canvas || typeof canvas.initGroupLayout !== 'function') {
			return false;
		}
		if (canvas.initGroupLayout._vikusWp) {
			return true;
		}

		var originalGroup = canvas.initGroupLayout;
		canvas.initGroupLayout = function () {
			originalGroup.apply(this, arguments);
			if (!canvas.x || typeof canvas.x.domain !== 'function') {
				return;
			}
			var domain = canvas.x.domain().filter(isUsableGroupKey);
			canvas.x.domain(domain);
		};
		canvas.initGroupLayout._vikusWp = true;
		return true;
	}

	function patchTimelineInit() {
		if (typeof timeline === 'undefined' || !timeline || typeof timeline.init !== 'function') {
			return false;
		}
		if (timeline.init._vikusWp) {
			return true;
		}

		var originalTimelineInit = timeline.init;
		timeline.init = function (timeDomain) {
			var filtered = (timeDomain || []).filter(function (entry) {
				return entry && isUsableGroupKey(entry.key);
			});
			return originalTimelineInit.call(this, filtered);
		};
		timeline.init._vikusWp = true;
		return true;
	}

	function patchCanvas() {
		var detailOk = patchShowDetail();
		var groupOk = patchInitGroupLayout();
		var timelineOk = patchTimelineInit();
		return detailOk && groupOk && timelineOk;
	}

	function apply() {
		patchDetailVue();
		if (!patchCanvas()) {
			// Canvas is created in viz.js init(); retry briefly if needed.
			var tries = 0;
			var timer = setInterval(function () {
				tries += 1;
				if (patchCanvas() || tries > 40) {
					clearInterval(timer);
				}
			}, 50);
		}
	}

	apply();
})();
