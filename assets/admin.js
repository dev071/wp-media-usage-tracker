/**
 * WP Media Usage Tracker - Admin Script
 */

(function ($) {
	"use strict";

	if (typeof wmutData === "undefined") {
		return;
	}

	const state = {
		scanData: wmutData.scanData || {},
		currentTab: "all",
		searchQuery: "",
		filterMime: "",
		filterPostType: "",
		filterPostStatus: "",
		sortBy: "usages-desc",
		perPage: 50,
		currentPage: 1,
	};

	let searchTimer = null;

	function init() {
		populateFilters();
		bindEvents();
		render();
	}

	function populateFilters() {
		// Populate Post Types dropdown
		const $typeSelect = $("#wmut-filter-post-type");
		$typeSelect.find("option:not(:first)").remove();
		if (wmutData.availableTypes) {
			$.each(wmutData.availableTypes, function (key, label) {
				$typeSelect.append($("<option>", { value: key, text: label }));
			});
		}

		// Populate Post Statuses dropdown
		const $statusSelect = $("#wmut-filter-post-status");
		$statusSelect.find("option:not(:first)").remove();
		if (wmutData.availableStati) {
			$.each(wmutData.availableStati, function (key, label) {
				$statusSelect.append($("<option>", { value: key, text: label }));
			});
		}
	}

	function bindEvents() {
		// Tab clicks
		$(".wmut-tab").on("click", function () {
			$(".wmut-tab").removeClass("active");
			$(this).addClass("active");
			state.currentTab = $(this).data("tab");
			state.currentPage = 1;
			render();
		});

		// Search input
		$("#wmut-search-input").on("input", function () {
			const val = $(this).val();
			$("#wmut-search-clear").toggle(val.length > 0);
			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				state.searchQuery = val.trim().toLowerCase();
				state.currentPage = 1;
				render();
			}, 200);
		});

		// Clear search
		$("#wmut-search-clear").on("click", function () {
			$("#wmut-search-input").val("");
			$(this).hide();
			state.searchQuery = "";
			state.currentPage = 1;
			render();
		});

		// Dropdown filters
		$("#wmut-filter-mime").on("change", function () {
			state.filterMime = $(this).val();
			state.currentPage = 1;
			render();
		});

		$("#wmut-filter-post-type").on("change", function () {
			state.filterPostType = $(this).val();
			state.currentPage = 1;
			render();
		});

		$("#wmut-filter-post-status").on("change", function () {
			state.filterPostStatus = $(this).val();
			state.currentPage = 1;
			render();
		});

		$("#wmut-sort-select").on("change", function () {
			state.sortBy = $(this).val();
			state.currentPage = 1;
			render();
		});

		$("#wmut-per-page").on("change", function () {
			state.perPage = parseInt($(this).val(), 10) || 50;
			state.currentPage = 1;
			render();
		});

		// Reset filters
		$("#wmut-btn-reset-filters").on("click", function () {
			resetFilters();
		});

		// Re-Scan button
		$("#wmut-btn-rescan").on("click", function () {
			triggerRescan();
		});

		// Toggle expanded usages
		$(document).on("click", ".wmut-more-usages", function (e) {
			e.preventDefault();
			const $btn = $(this);
			const $container = $btn.closest("td").find(".wmut-usage-extra");
			const isCurrentlyVisible = $container.is(":visible");

			if (isCurrentlyVisible) {
				$btn.text($btn.data("text-more"));
				$container.slideUp(150);
			} else {
				$btn.text($btn.data("text-less"));
				$container.slideDown(150, function () {
					$(this).css("display", "flex");
				});
			}
		});

		// Pagination clicks
		$(document).on("click", ".wmut-page-btn", function () {
			const page = $(this).data("page");
			if (page && page !== state.currentPage) {
				state.currentPage = parseInt(page, 10);
				render();
				$("html, body").animate({ scrollTop: $(".wmut-toolbar-card").offset().top - 40 }, 200);
			}
		});
	}

	function resetFilters() {
		state.currentTab = "all";
		state.searchQuery = "";
		state.filterMime = "";
		state.filterPostType = "";
		state.filterPostStatus = "";
		state.sortBy = "usages-desc";
		state.currentPage = 1;

		$(".wmut-tab").removeClass("active").filter('[data-tab="all"]').addClass("active");
		$("#wmut-search-input").val("");
		$("#wmut-search-clear").hide();
		$("#wmut-filter-mime").val("");
		$("#wmut-filter-post-type").val("");
		$("#wmut-filter-post-status").val("");
		$("#wmut-sort-select").val("usages-desc");

		render();
	}

	function triggerRescan() {
		const $btn = $("#wmut-btn-rescan");
		if ($btn.hasClass("is-loading")) {
			return;
		}

		$btn.addClass("is-loading");
		$btn.find(".btn-text").text(wmutData.i18n.scanning);
		showAlert("", false);

		$.ajax({
			url: wmutData.ajaxUrl,
			type: "POST",
			dataType: "json",
			data: {
				action: "wmut_rescan",
				nonce: wmutData.nonce,
			},
		})
			.done(function (response) {
				if (response.success && response.data.scanData) {
					state.scanData = response.data.scanData;
					wmutData.availableTypes = response.data.availableTypes;
					wmutData.availableStati = response.data.availableStati;

					updateKpis();
					populateFilters();
					render();

					showAlert(response.data.message || wmutData.i18n.scanSuccess, "success");
				} else {
					showAlert((response.data && response.data.message) || wmutData.i18n.scanError, "error");
				}
			})
			.fail(function () {
				showAlert(wmutData.i18n.scanError, "error");
			})
			.always(function () {
				$btn.removeClass("is-loading");
				$btn.find(".btn-text").text("Re-Scan Now");
			});
	}

	function updateKpis() {
		const d = state.scanData;
		$("#kpi-total-media").text(d.total_media.toLocaleString());
		$("#kpi-total-size").text(d.total_size_form + " total disk size");

		$("#kpi-used-media").text(d.used_media.toLocaleString());
		const usedPct = d.total_media > 0 ? ((d.used_media / d.total_media) * 100).toFixed(1) : 0;
		$("#kpi-used-pct").text(usedPct + "% of media library");

		$("#kpi-unused-media").text(d.unused_media.toLocaleString());
		const unusedPct = d.total_media > 0 ? ((d.unused_media / d.total_media) * 100).toFixed(1) : 0;
		$("#kpi-unused-meta").html(unusedPct + "% &bull; " + d.unused_size_form + " space wasted");

		$("#kpi-total-usages").text(d.total_usages.toLocaleString());

		$("#tab-count-all").text(d.total_media);
		$("#tab-count-used").text(d.used_media);
		$("#tab-count-unused").text(d.unused_media);

		$("#wmut-scanned-date").text(d.scanned_formatted);
	}

	function showAlert(msg, type) {
		const $alert = $("#wmut-alert");
		if (!msg) {
			$alert.hide().empty();
			return;
		}

		$alert
			.removeClass("wmut-alert-success wmut-alert-error")
			.addClass(type === "success" ? "wmut-alert-success" : "wmut-alert-error")
			.html(msg)
			.fadeIn(200);

		if (type === "success") {
			setTimeout(function () {
				$alert.fadeOut(300);
			}, 4000);
		}
	}

	function getFilteredItems() {
		let items = (state.scanData.items || []).slice();

		// 1. Tab filter
		if (state.currentTab === "used") {
			items = items.filter(function (item) {
				return item.usage_count > 0;
			});
		} else if (state.currentTab === "unused") {
			items = items.filter(function (item) {
				return item.usage_count === 0;
			});
		}

		// 2. Search query
		if (state.searchQuery) {
			const q = state.searchQuery;
			items = items.filter(function (item) {
				return (
					(item.title && item.title.toLowerCase().indexOf(q) !== -1) ||
					(item.filename && item.filename.toLowerCase().indexOf(q) !== -1) ||
					String(item.id).indexOf(q) !== -1
				);
			});
		}

		// 3. MIME type filter
		if (state.filterMime) {
			items = items.filter(function (item) {
				return item.type_group === state.filterMime;
			});
		}

		// 4. Post Type filter
		if (state.filterPostType) {
			items = items.filter(function (item) {
				return (
					item.usages &&
					item.usages.some(function (u) {
						return u.post_type === state.filterPostType;
					})
				);
			});
		}

		// 5. Post Status filter
		if (state.filterPostStatus) {
			items = items.filter(function (item) {
				return (
					item.usages &&
					item.usages.some(function (u) {
						return u.post_status === state.filterPostStatus;
					})
				);
			});
		}

		// 6. Sort
		items.sort(function (a, b) {
			switch (state.sortBy) {
				case "usages-desc":
					return b.usage_count - a.usage_count || b.id - a.id;
				case "usages-asc":
					return a.usage_count - b.usage_count || a.id - b.id;
				case "size-desc":
					return b.filesize - a.filesize;
				case "size-asc":
					return a.filesize - b.filesize;
				case "date-desc":
					return (b.upload_date || "").localeCompare(a.upload_date || "");
				case "date-asc":
					return (a.upload_date || "").localeCompare(b.upload_date || "");
				case "title-asc":
					return (a.title || "").localeCompare(b.title || "");
				default:
					return b.id - a.id;
			}
		});

		return items;
	}

	function render() {
		const filtered = getFilteredItems();
		const total = filtered.length;
		const perPage = state.perPage;
		const totalPages = Math.ceil(total / perPage) || 1;

		if (state.currentPage > totalPages) {
			state.currentPage = totalPages;
		}

		const startIndex = (state.currentPage - 1) * perPage;
		const endIndex = Math.min(startIndex + perPage, total);
		const pagedItems = filtered.slice(startIndex, endIndex);

		const $tbody = $("#wmut-table-body");
		$tbody.empty();

		if (total === 0) {
			$("#wmut-media-table").hide();
			$("#wmut-pagination").hide();
			$("#wmut-empty-state").show();
			$("#wmut-count-info").text("0 items found");
			return;
		}

		$("#wmut-media-table").show();
		$("#wmut-empty-state").hide();
		$("#wmut-pagination").show();

		$("#wmut-count-info").text(
			"Showing " + (startIndex + 1) + " - " + endIndex + " of " + total + " media items"
		);

		// Render rows
		const fragment = document.createDocumentFragment();

		pagedItems.forEach(function (item) {
			const tr = document.createElement("tr");

			// 1. Thumbnail
			const tdThumb = document.createElement("td");
			tdThumb.className = "col-thumb";
			tdThumb.innerHTML =
				'<div class="wmut-thumb-wrap">' +
				'<a href="' + escapeAttr(item.full_url) + '" target="_blank" rel="noopener">' +
				'<img src="' + escapeAttr(item.thumb_url) + '" alt="' + escapeAttr(item.title) + '" loading="lazy">' +
				"</a>" +
				"</div>";
			tr.appendChild(tdThumb);

			// 2. File Details
			const tdFile = document.createElement("td");
			tdFile.className = "col-file";
			let metaPillsHtml =
				'<div class="wmut-file-badges">' +
				'<span class="wmut-meta-pill mime-' + escapeAttr(item.type_group) + '">' + escapeHtml(item.type_group.toUpperCase()) + "</span>" +
				'<span class="wmut-meta-pill">' + escapeHtml(item.filesize_formatted) + "</span>";

			if (item.dimensions) {
				metaPillsHtml += '<span class="wmut-meta-pill">' + item.dimensions + "</span>";
			}

			metaPillsHtml += '<span class="wmut-meta-pill">' + escapeHtml(item.upload_formatted) + "</span>";
			metaPillsHtml += "</div>";

			tdFile.innerHTML =
				'<div class="wmut-file-title">' +
				'<a href="' + escapeAttr(item.edit_url) + '" target="_blank">' + escapeHtml(item.title) + "</a>" +
				"</div>" +
				'<div class="wmut-file-name" title="' + escapeAttr(item.filename) + '">' + escapeHtml(item.filename) + "</div>" +
				metaPillsHtml;
			tr.appendChild(tdFile);

			// 3. Status Badge
			const tdStatus = document.createElement("td");
			tdStatus.className = "col-status";
			if (item.usage_count === 0) {
				tdStatus.innerHTML =
					'<span class="wmut-status-badge wmut-status-unused">' +
					'<span class="dashicons dashicons-no-alt"></span>' +
					"Unused" +
					"</span>";
			} else {
				tdStatus.innerHTML =
					'<span class="wmut-status-badge wmut-status-used">' +
					'<span class="dashicons dashicons-yes-alt"></span>' +
					(item.usage_count === 1 ? "Used 1 time" : "Used " + item.usage_count + " times") +
					"</span>";
			}
			tr.appendChild(tdStatus);

			// 4. Where Used
			const tdUsages = document.createElement("td");
			tdUsages.className = "col-usages";

			if (item.usage_count === 0) {
				tdUsages.innerHTML =
					'<span class="wmut-unused-notice">' +
					'<span class="dashicons dashicons-info-outline"></span>' +
					"Not detected in any posts, pages, custom fields, or theme settings." +
					"</span>";
			} else {
				const maxVisible = 3;
				let listHtml = '<ul class="wmut-usage-list">';
				let extraHtml = '';

				item.usages.forEach(function (u, index) {
					let statusClass = "wmut-status-" + escapeAttr(u.post_status);

					let contextBadges = "";
					if (u.contexts && u.contexts.length) {
						u.contexts.forEach(function (ctx) {
							contextBadges += '<span class="wmut-badge-context">' + escapeHtml(ctx) + "</span>";
						});
					}

					const itemHtml =
						'<li class="wmut-usage-item">' +
						'<span class="wmut-badge-type">' + escapeHtml(u.type_label) + "</span>" +
						'<a href="' + escapeAttr(u.edit_url) + '" class="wmut-post-link" target="_blank">' + escapeHtml(u.title) + "</a>" +
						'<span class="wmut-badge-status ' + statusClass + '">' + escapeHtml(u.status_label) + "</span>" +
						contextBadges +
						(u.view_url ? '<a href="' + escapeAttr(u.view_url) + '" class="wmut-view-ext" target="_blank" title="View page">&#x2197;</a>' : '') +
						"</li>";

					if (index < maxVisible) {
						listHtml += itemHtml;
					} else {
						extraHtml += itemHtml;
					}
				});

				listHtml += "</ul>";

				if (item.usages.length > maxVisible) {
					const remaining = item.usages.length - maxVisible;
					listHtml +=
						'<ul class="wmut-usage-list wmut-usage-extra" style="display:none; margin-top:6px;">' +
						extraHtml +
						"</ul>" +
						'<button type="button" class="wmut-more-usages" data-text-more="+ ' + remaining + ' more places" data-text-less="Show less">' +
						"+ " + remaining + " more places" +
						"</button>";
				}

				tdUsages.innerHTML = listHtml;
			}
			tr.appendChild(tdUsages);

			// 5. Actions
			const tdActions = document.createElement("td");
			tdActions.className = "col-actions";
			tdActions.innerHTML =
				'<div class="wmut-actions">' +
				'<a href="' + escapeAttr(item.full_url) + '" target="_blank" class="wmut-btn-icon" title="View full media file">' +
				'<span class="dashicons dashicons-visibility"></span>' +
				"</a>" +
				'<a href="' + escapeAttr(item.edit_url) + '" target="_blank" class="wmut-btn-icon" title="Edit in Media Library">' +
				'<span class="dashicons dashicons-edit"></span>' +
				"</a>" +
				"</div>";
			tr.appendChild(tdActions);

			fragment.appendChild(tr);
		});

		$tbody.append(fragment);

		renderPagination(total, totalPages);
	}

	function renderPagination(total, totalPages) {
		const $pagination = $("#wmut-pagination");
		$pagination.empty();

		if (totalPages <= 1) {
			$pagination.hide();
			return;
		}

		$pagination.show();

		const $info = $("<span>", {
			text: "Page " + state.currentPage + " of " + totalPages,
		});

		const $btnWrap = $("<div>", { class: "wmut-page-buttons" });

		// Prev
		const $prev = $("<button>", {
			type: "button",
			class: "wmut-page-btn",
			html: "&laquo; Prev",
			disabled: state.currentPage === 1,
			"data-page": state.currentPage - 1,
		});
		$btnWrap.append($prev);

		// Page numbers (max 7 visible)
		let startPage = Math.max(1, state.currentPage - 3);
		let endPage = Math.min(totalPages, state.currentPage + 3);

		if (startPage > 1) {
			$btnWrap.append($("<button>", { type: "button", class: "wmut-page-btn", text: "1", "data-page": 1 }));
			if (startPage > 2) {
				$btnWrap.append($("<span>", { class: "wmut-page-ellipsis", text: "..." }));
			}
		}

		for (let i = startPage; i <= endPage; i++) {
			const $p = $("<button>", {
				type: "button",
				class: "wmut-page-btn" + (i === state.currentPage ? " active" : ""),
				text: i,
				"data-page": i,
			});
			$btnWrap.append($p);
		}

		if (endPage < totalPages) {
			if (endPage < totalPages - 1) {
				$btnWrap.append($("<span>", { class: "wmut-page-ellipsis", text: "..." }));
			}
			$btnWrap.append($("<button>", { type: "button", class: "wmut-page-btn", text: totalPages, "data-page": totalPages }));
		}

		// Next
		const $next = $("<button>", {
			type: "button",
			class: "wmut-page-btn",
			html: "Next &raquo;",
			disabled: state.currentPage === totalPages,
			"data-page": state.currentPage + 1,
		});
		$btnWrap.append($next);

		$pagination.append($info).append($btnWrap);
	}

	function escapeHtml(str) {
		if (str === null || str === undefined) return "";
		var s = String(str);
		if (s.indexOf("&") !== -1) {
			var txt = document.createElement("textarea");
			txt.innerHTML = s;
			s = txt.value;
		}
		return s
			.replace(/&/g, "&amp;")
			.replace(/</g, "&lt;")
			.replace(/>/g, "&gt;")
			.replace(/"/g, "&quot;")
			.replace(/'/g, "&#039;");
	}

	function escapeAttr(str) {
		return escapeHtml(str);
	}

	$(document).ready(init);
})(jQuery);
