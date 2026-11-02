(function () {
	var root = document.getElementById( 'cp-dashboard-root' );
	if ( ! root || typeof cpDashboardConfig === 'undefined' ) {
		return;
	}

	var cardsEl        = document.getElementById( 'cp-dashboard-cards' );
	var noteEl         = document.getElementById( 'cp-dashboard-note' );
	var campaignSelect = document.getElementById( 'cp-campaign-select' );
	var rangeSelect    = document.getElementById( 'cp-range-select' );
	var customRangeEl  = document.getElementById( 'cp-custom-range' );
	var dateFromEl     = document.getElementById( 'cp-date-from' );
	var dateToEl       = document.getElementById( 'cp-date-to' );
	var refreshBtn     = document.getElementById( 'cp-refresh' );
	var settingsToggle = document.getElementById( 'cp-settings-toggle' );
	var settingsPanel  = document.getElementById( 'cp-settings-panel' );
	var costToggle     = document.getElementById( 'cp-cost-toggle' );
	var costFields     = document.getElementById( 'cp-cost-fields' );
	var costInput      = document.getElementById( 'cp-cost-input' );
	var costApply      = document.getElementById( 'cp-cost-apply' );
	var chartCanvas     = document.getElementById( 'cp-dashboard-chart' );

	var CARDS = [
		{ key: 'clicks', label: 'Клики', color: '#2f6fed' },
		{ key: 'unique_clicks', label: 'Уник. (кампанія)', color: '#8b5cf6' },
		{ key: 'conversions', label: 'Конв.', color: '#ec4899' },
		{ key: 'deposits', label: 'Депозити', color: '#eab308' },
		{ key: 'cost', label: 'Розхід', color: '#ea580c', money: true },
		{ key: 'confirmed_revenue', label: 'Дохід (підтверджений)', color: '#0e9488', money: true },
		{ key: 'roi_confirmed', label: 'ROI (підтв.)', color: '#0ea5e9', percent: true, id: 'cp-roi-value' }
	];

	var chart           = null;
	var lastConfirmedRevenue = 0;

	function qs( params ) {
		var usp = new URLSearchParams();
		Object.keys( params ).forEach( function ( key ) {
			if ( params[ key ] !== '' && params[ key ] !== null && typeof params[ key ] !== 'undefined' ) {
				usp.set( key, params[ key ] );
			}
		} );
		return usp.toString();
	}

	function apiGet( url, params ) {
		var full = url + '?' + qs( params );
		return fetch( full, { headers: { 'X-WP-Nonce': cpDashboardConfig.nonce } } ).then( function ( res ) {
			return res.json().then( function ( data ) {
				if ( ! res.ok ) {
					throw new Error( data && data.message ? data.message : 'Помилка запиту' );
				}
				return data;
			} );
		} );
	}

	function formatValue( card, value ) {
		if ( card.money ) {
			return Number( value ).toLocaleString( 'uk-UA', { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) + ' $';
		}
		if ( card.percent ) {
			return Number( value ).toFixed( 2 ) + '%';
		}
		return Number( value ).toLocaleString( 'uk-UA' );
	}

	function renderCards( totals ) {
		lastConfirmedRevenue = Number( totals.confirmed_revenue ) || 0;

		var html = '';
		CARDS.forEach( function ( card ) {
			var idAttr = card.id ? ' id="' + card.id + '"' : '';
			html += '<div class="cp-card" style="--cp-card-color:' + card.color + '">' +
				'<div class="cp-card__label">' + card.label + '</div>' +
				'<div class="cp-card__value"' + idAttr + '>' + formatValue( card, totals[ card.key ] ) + '</div>' +
			'</div>';
		} );
		cardsEl.innerHTML = html;

		costToggle.checked  = false;
		costFields.hidden   = true;
	}

	function renderNote( payload ) {
		if ( 'cache_fallback' === payload.source ) {
			noteEl.hidden = false;
			noteEl.textContent = 'Keitaro зараз не відповідає — показані останні закешовані денні цифри замість погодинного графіка в реальному часі.';
		} else {
			noteEl.hidden = true;
			noteEl.textContent = '';
		}
	}

	function bucketLabel( granularity, bucket ) {
		if ( 'hour' === granularity ) {
			return bucket;
		}
		var parts = bucket.split( '-' );
		return parts.length === 3 ? parts[ 2 ] + '.' + parts[ 1 ] : bucket;
	}

	function renderChart( payload ) {
		if ( typeof Chart === 'undefined' || ! chartCanvas ) {
			return;
		}

		var labels = payload.series.map( function ( row ) { return bucketLabel( payload.granularity, row.bucket ); } );

		var datasets = [
			{ label: 'Клики', data: payload.series.map( function ( r ) { return r.clicks; } ), borderColor: '#2f6fed', backgroundColor: 'rgba(47,111,237,0.12)', fill: true, yAxisID: 'yCount', tension: 0.3, pointRadius: 2 },
			{ label: 'Уник. (кампанія)', data: payload.series.map( function ( r ) { return r.unique_clicks; } ), borderColor: '#8b5cf6', backgroundColor: 'transparent', yAxisID: 'yCount', tension: 0.3, pointRadius: 2 },
			{ label: 'Конв.', data: payload.series.map( function ( r ) { return r.conversions; } ), borderColor: '#ec4899', backgroundColor: 'transparent', yAxisID: 'yCount', tension: 0.3, pointRadius: 2 },
			{ label: 'Депозити', data: payload.series.map( function ( r ) { return r.deposits; } ), borderColor: '#eab308', backgroundColor: 'transparent', yAxisID: 'yCount', tension: 0.3, pointRadius: 2 },
			{ label: 'Розхід', data: payload.series.map( function ( r ) { return r.cost; } ), borderColor: '#ea580c', backgroundColor: 'transparent', yAxisID: 'yMoney', tension: 0.3, pointRadius: 2, borderDash: [ 4, 3 ] },
			{ label: 'Дохід (підтв.)', data: payload.series.map( function ( r ) { return r.confirmed_revenue; } ), borderColor: '#0e9488', backgroundColor: 'transparent', yAxisID: 'yMoney', tension: 0.3, pointRadius: 2 },
			{ label: 'ROI (підтв.)', data: payload.series.map( function ( r ) { return r.roi_confirmed; } ), borderColor: '#0ea5e9', backgroundColor: 'transparent', yAxisID: 'yMoney', tension: 0.3, pointRadius: 2, borderDash: [ 2, 2 ] }
		];

		if ( chart ) {
			chart.data.labels   = labels;
			chart.data.datasets = datasets;
			chart.update();
			return;
		}

		chart = new Chart( chartCanvas.getContext( '2d' ), {
			type: 'line',
			data: { labels: labels, datasets: datasets },
			options: {
				responsive: true,
				interaction: { mode: 'index', intersect: false },
				scales: {
					yCount: { type: 'linear', position: 'left', beginAtZero: true, title: { display: true, text: 'Кількість' } },
					yMoney: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'USD / %' } }
				},
				plugins: { legend: { position: 'bottom' } }
			}
		} );
	}

	function currentParams() {
		var params = {};

		if ( campaignSelect.value ) {
			params.link_id = campaignSelect.value;
		}

		if ( 'custom' === rangeSelect.value ) {
			params.date_from = dateFromEl.value;
			params.date_to   = dateToEl.value;
		} else {
			params.preset = rangeSelect.value;
		}

		return params;
	}

	function load() {
		var params = currentParams();
		if ( 'custom' === rangeSelect.value && ( ! params.date_from || ! params.date_to ) ) {
			return;
		}

		cardsEl.innerHTML = '<p class="cp-dashboard__loading">Завантаження...</p>';

		apiGet( cpDashboardConfig.statsUrl, params )
			.then( function ( data ) {
				renderCards( data.totals );
				renderNote( data );
				renderChart( data );
			} )
			.catch( function ( err ) {
				cardsEl.innerHTML = '<p class="cp-dashboard__error">Помилка: ' + ( err.message || 'не вдалося завантажити дані' ) + '</p>';
			} );
	}

	function loadCampaigns() {
		apiGet( cpDashboardConfig.campaignsUrl, {} ).then( function ( items ) {
			items.forEach( function ( item ) {
				var opt = document.createElement( 'option' );
				opt.value = item.link_id;
				opt.textContent = item.name;
				campaignSelect.appendChild( opt );
			} );
		} ).catch( function () {
			// Список кампаній не критичний — лишаємо тільки "Усі кампанії".
		} );
	}

	function applyManualCost() {
		var roiEl = document.getElementById( 'cp-roi-value' );
		var cost  = parseFloat( costInput.value );
		if ( ! roiEl || ! cost || cost <= 0 ) {
			return;
		}
		var roi = ( ( lastConfirmedRevenue - cost ) / cost ) * 100;
		roiEl.textContent = roi.toFixed( 2 ) + '% (власний розрахунок)';
	}

	rangeSelect.addEventListener( 'change', function () {
		customRangeEl.hidden = 'custom' !== rangeSelect.value;
		if ( 'custom' !== rangeSelect.value ) {
			load();
		}
	} );
	campaignSelect.addEventListener( 'change', load );
	dateFromEl.addEventListener( 'change', load );
	dateToEl.addEventListener( 'change', load );
	refreshBtn.addEventListener( 'click', load );

	settingsToggle.addEventListener( 'click', function () {
		settingsPanel.hidden = ! settingsPanel.hidden;
	} );
	costToggle.addEventListener( 'change', function () {
		costFields.hidden = ! costToggle.checked;
	} );
	costApply.addEventListener( 'click', applyManualCost );

	var today = new Date().toISOString().slice( 0, 10 );
	var monthAgo = new Date();
	monthAgo.setDate( monthAgo.getDate() - 30 );
	dateFromEl.value = monthAgo.toISOString().slice( 0, 10 );
	dateToEl.value   = today;

	loadCampaigns();
	load();
})();
