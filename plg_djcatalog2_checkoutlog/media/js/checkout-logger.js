(function () {
    'use strict';

    var cfg = (typeof Joomla !== 'undefined' && typeof Joomla.getOptions === 'function')
        ? Joomla.getOptions('plg_djcatalog2_checkoutlog', {})
        : {};
    var ajaxUrl = cfg.ajaxUrl || '';

    var distanceData = null;

    // Patch XHR immediately — must be in place before DJ Catalog 2 fires getSummary
    var origOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url) {
        if (url && String(url).indexOf('getSummary') !== -1) {
            this.addEventListener('load', function () {
                try { logDeliveryQuote(JSON.parse(this.responseText)); } catch (e) {}
            });
        }
        return origOpen.apply(this, arguments);
    };

    // Also cover fetch()
    if (window.fetch) {
        var origFetch = window.fetch;
        window.fetch = function (url, opts) {
            var p = origFetch.apply(this, arguments);
            var s = String(url || '');
            if (s.indexOf('getSummary') !== -1 && s.indexOf('checkoutlog') === -1) {
                p.then(function (r) {
                    r.clone().json().then(logDeliveryQuote).catch(function () {});
                }).catch(function () {});
            }
            return p;
        };
    }

    // Hook Google Maps Distance Matrix — polls until the API is available
    function hookDistanceMatrix() {
        if (typeof google === 'undefined' || !google.maps || !google.maps.DistanceMatrixService) {
            setTimeout(hookDistanceMatrix, 400);
            return;
        }
        var orig = google.maps.DistanceMatrixService.prototype.getDistanceMatrix;
        google.maps.DistanceMatrixService.prototype.getDistanceMatrix = function (req, cb) {
            return orig.call(this, req, function (response, status) {
                if (status === 'OK') {
                    try {
                        var el = response.rows[0].elements[0];
                        if (el.status === 'OK') {
                            distanceData = {
                                km:       el.distance ? Math.round(el.distance.value / 100) / 10 : null,
                                text:     el.distance ? el.distance.text : '',
                                duration: el.duration ? el.duration.text : ''
                            };
                        }
                    } catch (e) {}
                }
                cb(response, status);
            });
        };
    }

    // Scripts load at the bottom of the page, so the DOM is already parsed here.
    // Only start the Google Maps polling on the checkout page — avoids an infinite
    // setTimeout on product listing / cart pages where Google Maps never loads.
    if (document.getElementById('jform_djcatalog2profile_postcode')) {
        hookDistanceMatrix();
    }

    function getDeliveryOptions() {
        var opts = [];
        document.querySelectorAll(
            'input[name="jform[djcatalog2orderdetails][delivery_method_id]"]'
        ).forEach(function (r) {
            var lbl = document.querySelector('label[for="' + r.id + '"]');
            opts.push({ id: r.value, label: lbl ? lbl.textContent.trim() : '', selected: r.checked });
        });
        return opts;
    }

    function stripCurrency(s) {
        return parseFloat(String(s).replace(/[^0-9.]/g, '')) || 0;
    }

    function logDeliveryQuote(summary) {
        if (!ajaxUrl) { return; }
        var postcodeEl = document.getElementById('jform_djcatalog2profile_postcode');
        if (!postcodeEl) { return; }
        if (!summary || summary.error) { return; }
        var d = summary.data || {};
        var params = new URLSearchParams({
            postcode:         postcodeEl.value.trim(),
            distance_km:      distanceData ? (distanceData.km !== null ? distanceData.km : '') : '',
            distance_text:    distanceData ? distanceData.text     : '',
            duration_text:    distanceData ? distanceData.duration  : '',
            delivery_options: JSON.stringify(getDeliveryOptions()),
            delivery_id:      summary.delivery || 0,
            products_total:   stripCurrency(d.products || ''),
            delivery_cost:    stripCurrency(d.delivery || ''),
            payment_cost:     stripCurrency(d.payment  || ''),
            grand_total:      stripCurrency(d.total    || '')
        });
        fetch(ajaxUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    params.toString()
        }).catch(function () {});
    }
}());
