/**
 * PaintSoko Paint Calculator v2 — Admin JS
 */
(function ($) {
    'use strict';

    $(document).ready(function () {

        /* ── Color Pickers ──────────────────────────────────────── */
        $('.pspc-color-picker').wpColorPicker();

        /* ── Live Tin Preview ───────────────────────────────────── */
        $('#pspc_tin_sizes').on('input', debounce(function () {
            const raw   = $(this).val();
            const sizes = raw.split(',')
                .map(function (s) { return parseFloat(s.trim()); })
                .filter(function (s) { return !isNaN(s) && s > 0; });

            if ( sizes.length === 0 ) {
                $('#pspc-tin-combo-preview').text('—');
                return;
            }

            sizes.sort(function (a, b) { return b - a; });
            $('#pspc-tin-combo-preview').text(getTinCombination(52, sizes));
        }, 350));

        /* ── Highlight saved notice ─────────────────────────────── */
        if ( window.location.search.indexOf('settings-updated') !== -1 ) {
            const notice = $(
                '<div class="notice notice-success is-dismissible pspc-saved-notice">' +
                '<p><strong>Settings saved.</strong> Your paint calculator has been updated.</p>' +
                '</div>'
            );
            $('.pspc-admin-wrap > .pspc-nav-tabs').before(notice);
            setTimeout(function () {
                notice.fadeOut(400, function () { $(this).remove(); });
            }, 4000);
        }

        /* ── Warn on unsaved changes ────────────────────────────── */
        let formChanged = false;
        $('#pspc-settings-form').on('change input', function () { formChanged = true; });
        $('#pspc-settings-form').on('submit',        function () { formChanged = false; });
        $(window).on('beforeunload', function () {
            if ( formChanged ) {
                return 'You have unsaved changes. Are you sure you want to leave?';
            }
        });

    });

    /* ── Greedy tin combination (mirrors frontend logic) ────────── */
    function getTinCombination(litres, sizes) {
        const combo     = [];
        let   remaining = litres;

        for (const size of sizes) {
            if ( remaining <= 0 ) break;
            const count = Math.floor(remaining / size);
            if ( count > 0 ) {
                combo.push(count + '\u00d7 ' + size + 'L');
                remaining -= count * size;
            }
        }

        if ( remaining > 0 && sizes.length > 0 ) {
            const reversed = [ ...sizes ].reverse();
            const coverTin = reversed.find(function (s) { return s >= remaining; }) || sizes[sizes.length - 1];
            combo.push('1\u00d7 ' + coverTin + 'L');
        }

        return combo.length ? combo.join(' + ') : '—';
    }

    /* ── Debounce ───────────────────────────────────────────────── */
    function debounce(fn, delay) {
        let timer;
        return function () {
            const ctx  = this;
            const args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
        };
    }

}(jQuery));
