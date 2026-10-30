(function () {
	var cardsEl = document.getElementById( 'cp-dashboard-cards' );
	var fromEl = document.getElementById( 'cp-date-from' );
	var toEl = document.getElementById( 'cp-date-to' );
	var btn = document.getElementById( 'cp-apply-filter' );
	var costToggle = document.getElementById( 'cp-cost-toggle' );
	var costFields = document.getElementById( 'cp-cost-fields' );
	var costInput = document.getElementById( 'cp-cost-input' );
	var costApply = document.getElementById( 'cp-cost-apply' );

	if ( ! cardsEl || ! fromEl || ! toEl || ! btn ) {
		return;
	}

	var lastRevenue = 0;

	function todayISO() {
		return new Date().toISOString().slice( 0, 10 );
	}

	function daysAgoISO( days ) {
		var d = new Date();
		d.setDate( d.getDate() - days );
		return d.toISOString().slice( 0, 10 );
	}

	var LABELS = {
		clicks: 'Clicks',
		registrations: 'Registrations',
		ftd: 'FTD',
		deposits: 'Deposits',
		revenue: 'Revenue',
		roi: 'ROI'
	};

	function renderCards( totals ) {
		lastRevenue = Number( totals.revenue ) || 0;

		var html = '';
		Object.keys( LABELS ).forEach( function ( key ) {
			var value = totals[ key ];
			var idAttr = '';
			if ( 'revenue' === key ) {
				value = Number( value ).toFixed( 2 );
			} else if ( 'roi' === key ) {
				value = Number( value ).toFixed( 2 ) + '%';
				idAttr = ' id="cp-roi-value"';
			}
			html += '<div class="cp-card"><div class="cp-card__label">' + LABELS[ key ] + '</div><div class="cp-card__value"' + idAttr + '>' + value + '</div></div>';
		} );
		cardsEl.innerHTML = html;

		if ( costToggle ) {
			costToggle.checked = false;
		}
		if ( costFields ) {
			costFields.hidden = true;
		}
	}

	function applyManualCost() {
		var roiEl = document.getElementById( 'cp-roi-value' );
		var cost = parseFloat( costInput.value );
		if ( ! roiEl || ! cost || cost <= 0 ) {
			return;
		}
		var roi = ( ( lastRevenue - cost ) / cost ) * 100;
		roiEl.textContent = roi.toFixed( 2 ) + '% (власний розрахунок)';
	}

	function load() {
		cardsEl.innerHTML = '<p>Завантаження...</p>';
		var params = new URLSearchParams( {
			date_from: fromEl.value,
			date_to: toEl.value
		} );

		fetch( cpDashboardConfig.restUrl + '?' + params.toString(), {
			headers: { 'X-WP-Nonce': cpDashboardConfig.nonce }
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				if ( data.code ) {
					cardsEl.innerHTML = '<p>Помилка: ' + ( data.message || data.code ) + '</p>';
					return;
				}
				renderCards( data.totals );
			} )
			.catch( function () {
				cardsEl.innerHTML = '<p>Не вдалося завантажити дані.</p>';
			} );
	}

	fromEl.value = daysAgoISO( 30 );
	toEl.value = todayISO();
	btn.addEventListener( 'click', load );

	if ( costToggle && costFields ) {
		costToggle.addEventListener( 'change', function () {
			costFields.hidden = ! costToggle.checked;
		} );
	}
	if ( costApply ) {
		costApply.addEventListener( 'click', applyManualCost );
	}

	load();
})();
