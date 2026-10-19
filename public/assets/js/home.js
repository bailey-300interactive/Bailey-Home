/**
 * Home: shared JS (framework app.js + Home's global pieces)
 * @author Bailey Rotellini
 */

/* ---------- Framework alert ---------- */
function ShowAlert(message, type, positionX, positionY, duration) {
    if (!message) return false;
    if (type === 'info' || !type) type = 'default';
    if (!window.notyf) { console.log(type, message); return; }
    window.notyf.open({
        type: type === 'error' ? 'danger' : type,
        message: $('<div>').text(message).html(),
        duration: duration || 4500,
        ripple: true,
        dismissible: true,
        position: { x: positionX || 'right', y: positionY || 'top' }
    });
}

/* ---------- Ajax helper with CSRF ---------- */
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

function api(url, data, opts) {
    opts = opts || {};
    const isForm = data instanceof FormData;
    if (isForm) { if (!data.has('csrf')) data.append('csrf', CSRF); }
    else { data = Object.assign({ csrf: CSRF }, data || {}); }
    return $.ajax({
        url: url,
        method: opts.method || 'POST',
        data: data,
        dataType: 'json',
        processData: !isForm,
        contentType: isForm ? false : 'application/x-www-form-urlencoded; charset=UTF-8',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF }
    }).then(function (r) {
        if (r && r.result === false) {
            if (!opts.quiet) ShowAlert(r.message || 'Something went wrong', 'error');
            return $.Deferred().reject(r).promise();
        }
        return r;
    }, function (xhr) {
        const r = xhr.responseJSON || {};
        if (xhr.status === 423 && r.locked) { window.location = '/vault'; }
        else if (!opts.quiet) ShowAlert(r.message || ('Request failed (' + xhr.status + ')'), 'error');
        return $.Deferred().reject(r).promise();
    });
}

function fmtMoney(n) {
    n = Number(n || 0);
    const s = Math.abs(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const sym = { USD: '$', CAD: '$', AUD: '$', EUR: '€', GBP: '£' }[(window.HOME || {}).currency] || '$';
    return (n < 0 ? '-' : '') + sym + s;
}
function fmtHM(sec) {
    sec = Math.max(0, Math.floor(sec));
    const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    return h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
}
function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
function copyText(text, label) {
    const done = () => ShowAlert((label || 'Copied') + ' to clipboard', 'success', null, null, 1800);
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, () => fallbackCopy(text, done));
    } else fallbackCopy(text, done);
}
function fallbackCopy(text, done) {
    const t = document.createElement('textarea');
    t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
    document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); done(); } catch (e) { ShowAlert('Could not copy', 'error'); }
    document.body.removeChild(t);
}
function refeather() { if (window.feather) feather.replace(); }

/* ---------- Running timer in the top bar ---------- */
let timerInterval = null;
function startTimerTicker() {
    clearInterval(timerInterval);
    timerInterval = setInterval(function () {
        document.querySelectorAll('[data-start]').forEach(function (el) {
            const start = Number(el.dataset.start);
            (el.querySelector('.elapsed') || el).textContent = fmtHM(Date.now() / 1000 - start);
        });
        const pill = document.getElementById('timerPill');
        if (pill) document.title = '⏱ ' + pill.querySelector('.elapsed').textContent + ' · ' + document.title.replace(/^⏱ [\d:]+ · /, '');
    }, 1000);
}

$(document).on('click', '.js-timer-stop', function (e) {
    e.preventDefault();
    api('/app/Ajax/time.php', { action: 'stop' }).then(function (r) {
        ShowAlert('Timer stopped: ' + r.duration + ' logged', 'success');
        setTimeout(() => window.location.reload(), 600);
    });
});

/* ---------- Quick add ---------- */
function openQuick(tab) {
    const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('quickModal'));
    m.show();
    switchQuick(tab || 'reminder');
}
function switchQuick(tab) {
    $('.quick-tabs button[data-tab]').removeClass('active').filter('[data-tab="' + tab + '"]').addClass('active');
    $('.quick-pane').addClass('hidden').filter('[data-pane="' + tab + '"]').removeClass('hidden');
    const focus = () => $('.quick-pane[data-pane="' + tab + '"]').find('input[type=text], input:not([type])').first().trigger('focus');
    if ($('#quickModal').hasClass('show')) focus(); else $('#quickModal').one('shown.bs.modal', focus);
}
$(document).on('click', '.js-quick', function (e) { e.preventDefault(); openQuick($(this).data('tab')); });
$(document).on('click', '.quick-tabs button[data-tab]', function () { switchQuick($(this).data('tab')); });

let parseTimer = null;
$(document).on('input', '#quickModal [name="text"]', function () {
    const v = this.value;
    clearTimeout(parseTimer);
    parseTimer = setTimeout(function () {
        if (!v.trim()) { $('#parsePreview').html(''); return; }
        api('/app/Ajax/reminders.php', { action: 'parse', text: v }, { quiet: true }).then(r => $('#parsePreview').html(r.html));
    }, 220);
});

$(document).on('submit', '.quick-pane', function (e) {
    e.preventDefault();
    const $f = $(this), btn = $f.find('button.btn-primary').prop('disabled', true);
    api($f.data('endpoint'), new FormData(this)).then(function (r) {
        ShowAlert(r.message || 'Saved', 'success');
        bootstrap.Modal.getInstance(document.getElementById('quickModal')).hide();
        $f[0].reset();
        $('#parsePreview').html('');
        setTimeout(() => window.location.reload(), 500);
    }).always(() => btn.prop('disabled', false));
});

$(document).on('click', '.js-quick-start', function () {
    const f = $(this).closest('form')[0];
    const fd = new FormData(f);
    fd.set('action', 'start');
    api('/app/Ajax/time.php', fd).then(function () {
        ShowAlert('Timer started', 'success');
        setTimeout(() => window.location.reload(), 400);
    });
});

$(document).on('change', 'input[type=file][name=receipt]', function () {
    $(this).closest('form').find('.js-file-name').text(this.files[0] ? this.files[0].name : '');
});

// Guess the category from the payee
$(document).on('change', '.js-payee', function () {
    const $cat = $(this).closest('form').find('.js-category');
    if (!this.value || ($cat.val() && $cat.data('touched'))) return;
    api('/app/Ajax/money.php', { action: 'guess', payee: this.value }, { quiet: true }).then(r => { if (r.category_id) $cat.val(r.category_id); });
});
$(document).on('change', '.js-category', function () { $(this).data('touched', 1); });

/* ---------- Search palette ---------- */
let searchTimer = null, searchSel = -1;
$('#searchModal').on('shown.bs.modal', () => $('#searchInput').trigger('focus').select());
$(document).on('input', '#searchInput', function () {
    const v = this.value.trim();
    clearTimeout(searchTimer);
    if (v.length < 2) return;
    searchTimer = setTimeout(function () {
        api('/app/Ajax/search.php', { q: v }, { quiet: true }).then(function (r) {
            searchSel = -1;
            if (!r.groups.length) { $('#searchResults').html('<div class="p-4 text-center small-muted">Nothing found for “' + esc(v) + '”</div>'); return; }
            let html = '';
            r.groups.forEach(function (g) {
                html += '<div class="group">' + esc(g.label) + '</div>';
                g.items.forEach(function (it) {
                    html += '<a href="' + esc(it.url) + '"><span class="emoji-badge" style="width:28px;height:28px;font-size:.9rem">' + esc(it.icon || '•') + '</span><span class="truncate">' + esc(it.title) + '</span><span class="sub">' + esc(it.sub || '') + '</span></a>';
                });
            });
            $('#searchResults').html(html);
        });
    }, 160);
});
$(document).on('keydown', '#searchInput', function (e) {
    const links = $('#searchResults a');
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        searchSel = Math.max(0, Math.min(links.length - 1, searchSel + (e.key === 'ArrowDown' ? 1 : -1)));
        links.removeClass('sel').eq(searchSel).addClass('sel')[0]?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
        const a = links.eq(Math.max(0, searchSel));
        if (a.length) window.location = a.attr('href');
    }
});

/* ---------- Keyboard shortcuts ---------- */
let gPressed = 0;
$(document).on('keydown', function (e) {
    const tag = (e.target.tagName || '').toLowerCase();
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('searchModal')).show();
        return;
    }
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable || e.ctrlKey || e.metaKey || e.altKey) return;
    if ($('.modal.show').length) return;
    const k = e.key.toLowerCase();
    if (gPressed && Date.now() - gPressed < 900) {
        const go = { d: '/', v: '/vault', m: '/money', t: '/time', r: '/reminders', i: '/time/invoices', c: '/time/clients' }[k];
        gPressed = 0;
        if (go) { window.location = go; return; }
    }
    if (k === 'g') { gPressed = Date.now(); return; }
    if (k === 'r') { e.preventDefault(); openQuick('reminder'); }
    if (k === 't') { e.preventDefault(); openQuick('time'); }
    if (k === 'e') { e.preventDefault(); openQuick('expense'); }
    if (k === '/') { e.preventDefault(); bootstrap.Modal.getOrCreateInstance(document.getElementById('searchModal')).show(); }
});

/* ---------- Small conveniences ---------- */
// <form data-confirm="Delete this?">
$(document).on('submit', 'form[data-confirm]', function (e) {
    if (!confirm($(this).data('confirm'))) e.preventDefault();
});
$(document).on('click', '[data-confirm]:not(form)', function (e) {
    if (!confirm($(this).data('confirm'))) { e.preventDefault(); e.stopImmediatePropagation(); }
});
// Rows that link somewhere
$(document).on('click', 'tr[data-href], .list-row[data-href]', function (e) {
    if ($(e.target).closest('a, button, input, select, label, form').length) return;
    window.location = $(this).data('href');
});
// Auto-submit filter forms
$(document).on('change', 'form.js-autosubmit select, form.js-autosubmit input[type=date], form.js-autosubmit input[type=month]', function () {
    this.form.submit();
});
// Copy buttons
$(document).on('click', '[data-copy]', function (e) {
    e.preventDefault();
    copyText($(this).data('copy'), $(this).data('copy-label'));
});

$(function () {
    startTimerTicker();
    refeather();
});

/* ---------- Charts (ApexCharts, bundled with AdminKit) ---------- */
// Categorical order from the validated palette: blue, orange, aqua, yellow, magenta, green, violet, red
const SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
const INK = { muted: '#6c757d', grid: '#eef1f5' };
function chart(el, opts) {
    if (!window.ApexCharts || !document.querySelector(el)) return null;
    const base = {
        chart: { fontFamily: 'Inter, system-ui, sans-serif', toolbar: { show: false }, zoom: { enabled: false }, animations: { speed: 350 }, parentHeightOffset: 0 },
        colors: SERIES,
        grid: { borderColor: INK.grid, strokeDashArray: 3, padding: { left: 8, right: 8 } },
        dataLabels: { enabled: false },
        legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px', markers: { width: 10, height: 10, radius: 3 }, labels: { colors: INK.muted } },
        xaxis: { axisBorder: { show: false }, axisTicks: { show: false }, labels: { style: { colors: INK.muted, fontSize: '11px' } } },
        yaxis: { labels: { style: { colors: INK.muted, fontSize: '11px' } } },
        tooltip: { theme: 'light' },
        stroke: { width: 2, curve: 'smooth' },
        states: { hover: { filter: { type: 'darken', value: 0.9 } } },
    };
    const merged = $.extend(true, {}, base, opts);
    const c = new ApexCharts(document.querySelector(el), merged);
    c.render();
    return c;
}
const moneyAxis = v => fmtMoney(v).replace(/\.00$/, '').replace(/(\d)(\d{3})$/, '$1,$2');
const moneyShort = v => { const a = Math.abs(v); const s = a >= 1000 ? (a / 1000).toFixed(a >= 10000 ? 0 : 1) + 'k' : Math.round(a); return (v < 0 ? '-' : '') + '$' + s; };
