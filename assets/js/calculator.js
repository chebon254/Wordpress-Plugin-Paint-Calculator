/**
 * PaintSoko Paint Calculator v2 — Frontend JS
 *
 * Formula:
 *   Surface area (sq m) = length × height × sides
 *   Total litres needed  = (surface_area × coats) / spread_rate
 *   Packages needed      = ceil(total_litres / package_size)
 *   Estimated cost       = packages_needed × package_price
 */
(function () {
    'use strict';

    const cfg = window.pspcSettings || {};

    /* ── State ───────────────────────────────────────────────────── */
    let state = {
        products: [],      // [{id, name, spread, url}]
        packages: [],      // [{id, label, size, price}]
        selProduct: null,  // currently selected product object
        selPackage: null,  // currently selected package object
    };

    /* ── DOM refs ───────────────────────────────────────────────── */
    let elCategory, elPaint, elPackage,
        elLength, elHeight, elCoats, elSides,
        elCalcBtn,
        elDimensions, elRdPackage, elRdCoats,
        elRdPaint, elRdSpread, elRdLitres,
        elRdPkgRow, elRdPkgLabel, elRdPkgQty,
        elRdCost, elShopArea, elError;

    /* ── Init ───────────────────────────────────────────────────── */
    function init() {
        /* Form inputs */
        elCategory = document.getElementById('pspc-category');
        elPaint    = document.getElementById('pspc-paint');
        elPackage  = document.getElementById('pspc-package');
        elLength   = document.getElementById('pspc-length');
        elHeight   = document.getElementById('pspc-height');
        elCoats    = document.getElementById('pspc-coats');
        elSides    = document.getElementById('pspc-sides');
        elCalcBtn  = document.getElementById('pspc-calc-btn');

        /* Result cells */
        elDimensions  = document.getElementById('pspc-rd-dimensions');
        elRdPackage   = document.getElementById('pspc-rd-package');
        elRdCoats     = document.getElementById('pspc-rd-coats');
        elRdPaint     = document.getElementById('pspc-rd-paint');
        elRdSpread    = document.getElementById('pspc-rd-spread');
        elRdLitres    = document.getElementById('pspc-rd-litres');
        elRdPkgRow    = document.getElementById('pspc-rd-pkg-row');
        elRdPkgLabel  = document.getElementById('pspc-rd-pkg-label');
        elRdPkgQty    = document.getElementById('pspc-rd-pkg-qty');
        elRdCost      = document.getElementById('pspc-rd-cost');
        elShopArea    = document.getElementById('pspc-shop-area');
        elError       = document.getElementById('pspc-error');

        if ( ! elCalcBtn ) return;

        /* Category change → load products */
        if ( elCategory ) {
            elCategory.addEventListener('change', onCategoryChange);
        }

        /* Product change → load packages */
        if ( elPaint ) {
            elPaint.addEventListener('change', onPaintChange);
        }

        /* Calculate */
        elCalcBtn.addEventListener('click', handleCalculate);
    }

    /* ── Category change ─────────────────────────────────────────── */
    function onCategoryChange() {
        const catId = elCategory.value;
        resetSelect(elPaint,   '— Select Paint —');
        resetSelect(elPackage, '— Select Package —');
        state.selProduct = null;
        state.selPackage = null;
        state.products   = [];
        state.packages   = [];

        if ( ! catId ) return;

        setSelectLoading(elPaint, 'Loading paints…');

        ajaxPost('pspc_get_products', { cat_id: catId }, function (data) {
            state.products = data;
            resetSelect(elPaint, '— Select Paint —');

            data.forEach(function (p) {
                const opt = document.createElement('option');
                opt.value       = p.id;
                opt.textContent = p.name;
                elPaint.appendChild(opt);
            });

            elPaint.disabled = data.length === 0;
        }, function () {
            resetSelect(elPaint, '— Error loading paints —');
        });
    }

    /* ── Paint change ────────────────────────────────────────────── */
    function onPaintChange() {
        const productId = parseInt(elPaint.value, 10);
        resetSelect(elPackage, '— Select Package —');
        state.selProduct = null;
        state.selPackage = null;
        state.packages   = [];

        if ( ! productId ) return;

        state.selProduct = state.products.find(function (p) { return p.id === productId; }) || null;

        setSelectLoading(elPackage, 'Loading packages…');

        ajaxPost('pspc_get_packages', { product_id: productId }, function (data) {
            state.packages = data;
            resetSelect(elPackage, '— Select Package —');

            data.forEach(function (pkg) {
                const opt = document.createElement('option');
                opt.value       = pkg.id;
                opt.textContent = pkg.label;
                elPackage.appendChild(opt);
            });

            /* Auto-select if only one option */
            if ( data.length === 1 ) {
                elPackage.value  = data[0].id;
                state.selPackage = data[0];
            }

            elPackage.disabled = data.length === 0;

            /* Attach change listener once packages are loaded */
            elPackage.onchange = function () {
                const pkgId    = parseInt(elPackage.value, 10);
                state.selPackage = state.packages.find(function (pkg) { return pkg.id === pkgId; }) || null;
            };
        }, function () {
            resetSelect(elPackage, '— Error loading packages —');
        });
    }

    /* ── Main calculate ──────────────────────────────────────────── */
    function handleCalculate() {
        clearError();

        const length = parseFloat(elLength ? elLength.value : 0) || 0;
        const height = parseFloat(elHeight ? elHeight.value : 0) || 0;
        const coats  = Math.max(1, parseInt(elCoats ? elCoats.value : 1, 10) || 1);
        const sides  = Math.max(1, parseInt(elSides ? elSides.value : 1, 10) || 1);

        if ( length <= 0 ) { showError('Please enter a valid length.'); return; }
        if ( height <= 0 ) { showError('Please enter a valid height.'); return; }

        /* Spread rate: from selected product, fallback to global */
        const spreadRate = (state.selProduct && state.selProduct.spread > 0)
            ? state.selProduct.spread
            : (cfg.coverageRate || 13);

        /* Surface area and litres */
        const surfaceArea  = length * height * sides;       /* sq m */
        const litresRaw    = (surfaceArea * coats) / spreadRate;
        const litresNeeded = Math.ceil(litresRaw);

        /* Populate results */
        setText(elDimensions, length + ' × ' + height + ' × ' + sides);
        setText(elRdCoats,    coats + (coats === 1 ? ' coat' : ' coats'));
        setText(elRdSpread,   spreadRate + ' sq m/L');
        setText(elRdLitres,   litresNeeded.toLocaleString() + ' L');

        /* Paint name + link */
        if ( state.selProduct ) {
            if ( state.selProduct.url ) {
                const a      = document.createElement('a');
                a.href       = state.selProduct.url;
                a.textContent = state.selProduct.name;
                a.target     = '_blank';
                a.rel        = 'noopener noreferrer';
                if ( elRdPaint ) {
                    elRdPaint.innerHTML = '';
                    elRdPaint.appendChild(a);
                }
            } else {
                setText(elRdPaint, state.selProduct.name);
            }
        } else {
            setText(elRdPaint, '—');
        }

        /* Package info */
        if ( state.selPackage && state.selPackage.size > 0 ) {
            const pkg          = state.selPackage;
            const pkgsNeeded   = Math.ceil(litresNeeded / pkg.size);
            const hasPrice     = pkg.price > 0;
            const cost         = pkgsNeeded * pkg.price;

            setText(elRdPackage, pkg.label);

            if ( elRdPkgLabel ) elRdPkgLabel.textContent = pkg.label + ' :';
            if ( elRdPkgRow   ) elRdPkgRow.style.display = '';

            if ( hasPrice ) {
                setText(elRdPkgQty, 'x ' + pkgsNeeded + ' @ KSh. ' + formatCurrency(pkg.price));
                setText(elRdCost,   'KSh. ' + formatCurrency(cost));
            } else {
                setText(elRdPkgQty, 'x ' + pkgsNeeded + ' (price not set)');
                setText(elRdCost,   '—');
            }
        } else {
            /* No package selected — use tin recommendation */
            setText(elRdPackage, '—');
            if ( elRdPkgRow ) elRdPkgRow.style.display = 'none';
            setText(elRdCost, '—');

            if ( cfg.showTinRecommendation && cfg.tinSizes && cfg.tinSizes.length ) {
                const combo = getTinCombination(litresNeeded, cfg.tinSizes);
                setText(elRdPkgQty, combo);
                if ( elRdPkgLabel ) elRdPkgLabel.textContent = 'Recommended tins :';
                if ( elRdPkgRow   ) elRdPkgRow.style.display = '';
            }
        }

        /* Shop buttons */
        renderShopButtons();
    }

    /* ── Shop buttons ────────────────────────────────────────────── */
    function renderShopButtons() {
        if ( ! elShopArea ) return;
        elShopArea.innerHTML = '';
        elShopArea.hidden    = true;

        if ( ! cfg.wcEnabled ) return;

        const heading     = document.createElement('p');
        heading.className = 'pspc-shop-heading';
        heading.textContent = 'Ready to buy?';
        elShopArea.appendChild(heading);

        let hasBtn = false;

        if ( cfg.wcShowBrands ) {
            if ( cfg.wcDuracoatUrl ) {
                elShopArea.appendChild(makeShopBtn('Shop Duracoat', cfg.wcDuracoatUrl, 'pspc-btn-shop--primary'));
                hasBtn = true;
            }
            if ( cfg.wcPlascomUrl ) {
                elShopArea.appendChild(makeShopBtn('Shop Plascon', cfg.wcPlascomUrl, 'pspc-btn-shop--outline'));
                hasBtn = true;
            }
        } else {
            const url   = cfg.wcDuracoatUrl || cfg.wcPlascomUrl || '';
            const label = cfg.wcShopButtonText || 'Shop Paint Now';
            if ( url ) {
                elShopArea.appendChild(makeShopBtn(label, url, 'pspc-btn-shop--primary'));
                hasBtn = true;
            }
        }

        elShopArea.hidden = ! hasBtn;
    }

    function makeShopBtn(label, href, cls) {
        const a      = document.createElement('a');
        a.href       = href;
        a.className  = 'pspc-btn-shop ' + cls;
        a.target     = '_blank';
        a.rel        = 'noopener noreferrer';
        a.innerHTML  =
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="14" height="14">' +
            '<path d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm10 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zM7.17' +
            ' 14.75l.03-.12.9-1.63H17c.75 0 1.41-.41 1.75-1.03l3.86-7.01L20.95 4h-.01L19.9 6l-3.38 6H8.53L8.4 11.54 6.16 7 5.21' +
            ' 5H2V3H0v2h2l3.6 8.09L4.25 17c-.03.05-.05.11-.05.17C4.2 18.2 5 19 6 19h14v-2H6.42c-.13 0-.25-.11-.25-.25z"/>' +
            '</svg> ' + escapeHtml(label);
        return a;
    }

    /* ── Greedy tin combination ──────────────────────────────────── */
    function getTinCombination(litres, sizes) {
        const sorted    = [ ...sizes ].sort(function (a, b) { return b - a; });
        const combo     = [];
        let   remaining = litres;

        for (const size of sorted) {
            if ( remaining <= 0 ) break;
            const count = Math.floor(remaining / size);
            if ( count > 0 ) {
                combo.push(count + '\u00d7 ' + size + 'L');
                remaining -= count * size;
            }
        }

        if ( remaining > 0 && sorted.length ) {
            const coverTin = [ ...sorted ].reverse().find(function (s) { return s >= remaining; })
                || sorted[sorted.length - 1];
            combo.push('1\u00d7 ' + coverTin + 'L');
        }

        return combo.join(' + ') || '—';
    }

    /* ── AJAX helper ─────────────────────────────────────────────── */
    function ajaxPost(action, data, onSuccess, onError) {
        const body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce',  cfg.nonce || '');
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });

        fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    body.toString(),
        })
        .then(function (r) {
            if ( ! r.ok ) throw new Error('Network error ' + r.status);
            return r.json();
        })
        .then(function (json) {
            if ( json.success && Array.isArray(json.data) ) {
                onSuccess(json.data);
            } else {
                if ( onError ) onError(json.data);
            }
        })
        .catch(function () {
            if ( onError ) onError('Request failed.');
        });
    }

    /* ── DOM helpers ─────────────────────────────────────────────── */
    function setText(el, text) {
        if ( el ) el.textContent = text;
    }

    function resetSelect(el, placeholder) {
        if ( ! el ) return;
        el.innerHTML = '<option value="">' + placeholder + '</option>';
        el.disabled  = true;
    }

    function setSelectLoading(el, msg) {
        if ( ! el ) return;
        el.innerHTML = '<option value="">' + msg + '</option>';
        el.disabled  = true;
    }

    function showError(msg) {
        if ( ! elError ) return;
        elError.textContent = msg;
        elError.hidden      = false;
    }

    function clearError() {
        if ( ! elError ) return;
        elError.textContent = '';
        elError.hidden      = true;
    }

    function formatCurrency(value) {
        return Number(value).toLocaleString('en-KE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function escapeHtml(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ── Boot ───────────────────────────────────────────────────── */
    if ( document.readyState === 'loading' ) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
