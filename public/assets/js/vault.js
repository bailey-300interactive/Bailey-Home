/**
 * Vault UI: list, detail, edit, generator, strength meter, live 2FA codes, auto-lock.
 */

/* ---------- Strength (same scale as Vault::Strength in PHP) ---------- */
const COMMON = new Set(['password', 'password1', 'password123', '123456', '12345678', '123456789', '1234567890', 'qwerty', 'qwerty123', 'abc123', 'letmein', 'welcome', 'welcome1', 'admin', 'admin123', 'iloveyou', 'monkey', 'dragon', 'sunshine', 'princess', 'football', 'baseball', 'master', 'shadow', 'trustno1', '111111', '000000', 'passw0rd', 'changeme', 'secret', 'login', 'test', 'test123', 'root', 'toor']);
function strength(pw) {
    pw = pw || '';
    const len = [...pw].length;
    if (!len) return 0;
    if (COMMON.has(pw.toLowerCase())) return 0;
    let pool = 0;
    if (/[a-z]/.test(pw)) pool += 26;
    if (/[A-Z]/.test(pw)) pool += 26;
    if (/[0-9]/.test(pw)) pool += 10;
    if (/[^A-Za-z0-9]/.test(pw)) pool += 33;
    let bits = len * Math.log2(Math.max(pool, 1));
    if (new Set([...pw]).size < len / 2) bits *= 0.6;
    if (/(0123|1234|2345|3456|4567|5678|6789|abcd|qwer|asdf|zxcv)/i.test(pw)) bits -= 10;
    if (/^[A-Za-z][a-z]{2,}\d{1,4}[^A-Za-z0-9]?$/.test(pw)) bits = Math.min(bits, 35);
    return bits < 28 ? 0 : bits < 40 ? 1 : bits < 60 ? 2 : bits < 80 ? 3 : 4;
}
const STRENGTH_LABEL = ['Very weak', 'Weak', 'Fair', 'Strong', 'Very strong'];
function meter(sel, pw) {
    const s = pw ? strength(pw) : -1;
    $(sel).attr('class', 'strength' + (s >= 0 ? ' s' + s : '')).attr('title', s >= 0 ? STRENGTH_LABEL[s] : '');
}

/* ---------- Generators (all in the browser, crypto.getRandomValues) ---------- */
function randInt(max) {
    const a = new Uint32Array(1), limit = Math.floor(0x100000000 / max) * max;
    do { crypto.getRandomValues(a); } while (a[0] >= limit);
    return a[0] % max;
}
function generatePassword(len, opts) {
    opts = Object.assign({ upper: true, digits: true, symbols: true }, opts || {});
    const sets = ['abcdefghijkmnopqrstuvwxyz'];
    if (opts.upper) sets.push('ABCDEFGHJKLMNPQRSTUVWXYZ');
    if (opts.digits) sets.push('23456789');
    if (opts.symbols) sets.push('!@#$%^&*-_=+?');
    const all = sets.join('');
    const out = sets.map(s => s[randInt(s.length)]);
    while (out.length < len) out.push(all[randInt(all.length)]);
    for (let i = out.length - 1; i > 0; i--) { const j = randInt(i + 1); [out[i], out[j]] = [out[j], out[i]]; }
    return out.join('');
}
function passphrase(words, sep, cap, num) {
    const list = window.EFF_WORDS;
    if (!list) return generatePassword(24);
    const w = [];
    for (let i = 0; i < (words || 5); i++) {
        let x = list[randInt(list.length)];
        if (cap) x = x[0].toUpperCase() + x.slice(1);
        w.push(x);
    }
    if (num) w[randInt(w.length)] += randInt(10);
    return w.join(sep === undefined ? '-' : sep);
}
function colorize(pw) {
    return esc(pw).replace(/(\d+)/g, '<span class="d">$1</span>').replace(/([!@#$%^&*\-_=+?]+)/g, '<span class="s">$1</span>');
}
if (!window.EFF_WORDS) {
    const s = document.createElement('script'); s.src = '/public/vendor/eff-words.js'; document.head.appendChild(s);
}

/* ---------- 2FA codes, in the browser (RFC 6238) ---------- */
function b32decode(s) {
    const A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    s = s.toUpperCase().replace(/[^A-Z2-7]/g, '');
    let bits = '', out = [];
    for (const c of s) bits += A.indexOf(c).toString(2).padStart(5, '0');
    for (let i = 0; i + 8 <= bits.length; i += 8) out.push(parseInt(bits.substr(i, 8), 2));
    return new Uint8Array(out);
}
function parseTotp(input) {
    input = (input || '').trim();
    let secret = input, digits = 6, period = 30, algo = 'SHA-1';
    if (/^otpauth:\/\//i.test(input)) {
        try {
            const u = new URL(input);
            secret = u.searchParams.get('secret') || '';
            digits = parseInt(u.searchParams.get('digits') || '6', 10);
            period = parseInt(u.searchParams.get('period') || '30', 10);
            algo = { SHA256: 'SHA-256', SHA512: 'SHA-512' }[(u.searchParams.get('algorithm') || '').toUpperCase()] || 'SHA-1';
        } catch (e) { return null; }
    }
    secret = secret.replace(/[\s=-]/g, '').toUpperCase();
    if (!secret || /[^A-Z2-7]/.test(secret)) return null;
    return { secret, digits, period, algo };
}
async function totpNow(input) {
    const p = parseTotp(input);
    if (!p || !crypto.subtle) return null;
    const counter = Math.floor(Date.now() / 1000 / p.period);
    const buf = new ArrayBuffer(8); const view = new DataView(buf);
    view.setUint32(0, Math.floor(counter / 0x100000000)); view.setUint32(4, counter >>> 0);
    const key = await crypto.subtle.importKey('raw', b32decode(p.secret), { name: 'HMAC', hash: p.algo }, false, ['sign']);
    const h = new Uint8Array(await crypto.subtle.sign('HMAC', key, buf));
    const o = h[h.length - 1] & 15;
    const v = ((h[o] & 127) << 24 | h[o + 1] << 16 | h[o + 2] << 8 | h[o + 3]) % 10 ** p.digits;
    return { code: String(v).padStart(p.digits, '0'), left: p.period - Math.floor(Date.now() / 1000) % p.period, period: p.period };
}

/* ---------- The vault page ---------- */
const VaultApp = {
    items: [], folders: [], types: {}, filter: 'all', search: '', current: null, totpTimer: null,

    init(cfg) {
        Object.assign(this, cfg);
        this.render();
        $('.vault-nav a[data-filter]').on('click', e => {
            e.preventDefault();
            $('.vault-nav a').removeClass('active');
            $(e.currentTarget).addClass('active');
            this.filter = $(e.currentTarget).data('filter');
            this.render();
        });
        $('#vaultSearch').on('input', e => { this.search = e.target.value.toLowerCase().trim(); this.render(); });
        $(document).on('click', '.vault-item', e => this.open($(e.currentTarget).data('id')));
        $(document).on('click', '.js-new', e => { e.preventDefault(); this.edit(null, $(e.currentTarget).data('type')); });
        $('#itemForm [name=type]').on('change', e => this.showFields(e.target.value));
        $('#itemForm [name=password]').on('input', e => meter('#formMeter', e.target.value));
        $(document).on('click', '.js-toggle-pw', e => { const i = $(e.currentTarget).closest('.input-group').find('input')[0]; i.type = i.type === 'password' ? 'text' : 'password'; });
        $(document).on('click', '.js-gen', () => { const pw = generatePassword(20); $('#itemForm [name=password]').val(pw).attr('type', 'text').trigger('input'); });
        $(document).on('click', '.js-add-field', () => this.addField());
        $(document).on('click', '.js-del-field', e => $(e.currentTarget).closest('.cf').remove());
        $('#itemForm [name=folder_id]').on('change', e => this.newFolder(e.target));
        $('#itemForm').on('submit', e => { e.preventDefault(); this.save(); });
        // keyboard: / focuses search, n = new
        $(document).on('keydown', e => {
            if ($(e.target).is('input,textarea,select') || $('.modal.show').length || e.ctrlKey || e.metaKey) return;
            if (e.key === 'n') { e.preventDefault(); this.edit(null, 'login'); }
            if (e.key === 'f') { e.preventDefault(); $('#vaultSearch').trigger('focus'); }
        });
        if (this.openId) this.open(this.openId);
        if (this.newType) this.edit(null, this.newType);
        this.autoLock();
    },

    color(name) {
        const palette = ['#3b7ddd', '#1cbb8c', '#7a5af8', '#f0506e', '#f5a623', '#17a2b8', '#e83e8c', '#495057', '#20c997', '#6610f2', '#fd7e14'];
        let h = 0; for (const c of (name || '?')) h = (h * 31 + c.charCodeAt(0)) >>> 0;
        return palette[h % palette.length];
    },

    icon(it) {
        const t = { card: '💳', note: '📝', wifi: '📶', server: '🖥️' }[it.type];
        return t ? t : esc((it.name || '?').trim().charAt(0) || '?');
    },

    visible() {
        return this.items.filter(it => {
            const f = this.filter;
            if (f === 'favorites' && !it.favorite) return false;
            if (f.startsWith('type:') && it.type !== f.slice(5)) return false;
            if (f === 'folder:none' && it.folder_id) return false;
            if (f.startsWith('folder:') && f !== 'folder:none' && String(it.folder_id) !== f.slice(7)) return false;
            if (this.search && !it.search.includes(this.search)) return false;
            return true;
        });
    },

    render() {
        const list = this.visible();
        if (!list.length) {
            $('#vaultItems').html('<div class="empty"><div class="big">🔍</div>' + (this.items.length ? 'Nothing matches.' : 'No items yet.') + '</div>');
            return;
        }
        $('#vaultItems').html(list.map(it => `
            <div class="vault-item ${this.current === it.id ? 'active' : ''}" data-id="${it.id}">
                <div class="ico" style="background:${this.color(it.name)}">${this.icon(it)}</div>
                <div class="grow" style="min-width:0">
                    <div class="nm truncate">${esc(it.name)} ${it.favorite ? '<span class="text-warning">★</span>' : ''}</div>
                    <div class="un truncate">${esc(it.sub || '')}</div>
                </div>
                ${it.totp ? '<span class="chip chip-blue" title="Has 2FA codes">2FA</span>' : ''}
                ${it.weak ? '<span class="chip chip-red" title="Weak password">weak</span>' : ''}
            </div>`).join(''));
    },

    open(id) {
        this.current = id;
        $('.vault-item').removeClass('active').filter('[data-id="' + id + '"]').addClass('active');
        $('#vaultShell').addClass('show-detail');
        api('/app/Ajax/vault.php', { action: 'get', id }).then(r => this.detail(r.item));
    },

    field(label, value, opts) {
        opts = opts || {};
        if (value === undefined || value === null || value === '') return '';
        const secret = opts.secret;
        const shown = secret ? '•'.repeat(Math.min(16, Math.max(8, String(value).length))) : esc(value);
        return `<div class="vfield">
            <div class="grow"><div class="lbl">${esc(label)}</div>
                <div class="val ${opts.plain ? 'plain' : ''}" ${secret ? `data-secret="${esc(value)}"` : ''}>${opts.link ? `<a href="${esc(opts.link)}" target="_blank" rel="noopener noreferrer">${shown}</a>` : shown}</div>
                ${opts.extra || ''}
            </div>
            ${secret ? '<button type="button" class="btn btn-light btn-icon js-reveal" title="Show"><i data-feather="eye"></i></button>' : ''}
            <button type="button" class="btn btn-light btn-icon js-copy" data-v="${esc(value)}" data-l="${esc(label)}" title="Copy"><i data-feather="copy"></i></button>
        </div>`;
    },

    detail(it) {
        const d = it.d, t = this.types[it.type] || {};
        clearInterval(this.totpTimer);
        let url = d.url || '';
        if (url && !/^https?:\/\//i.test(url)) url = 'https://' + url;
        const s = it.strength;
        const strengthHtml = s !== null && s !== undefined ? `<div class="d-flex align-items-center gap-2 mt-1"><div class="strength s${s}" style="width:110px;margin:0"><span></span><span></span><span></span><span></span><span></span></div><span class="small-muted">${STRENGTH_LABEL[s]}${it.reused ? ' · used on ' + it.reused + ' other item' + (it.reused > 1 ? 's' : '') : ''}</span></div>` : '';
        let html = `<div class="d-md-none mb-2"><a href="#" class="js-back small"><i data-feather="chevron-left"></i> All items</a></div>
            <div class="hdr">
                <div class="ico" style="background:${this.color(d.name)}">${this.icon({ type: it.type, name: d.name })}</div>
                <div class="grow" style="min-width:0">
                    <h3 class="mb-0 h4 fw-bold truncate">${esc(d.name)}</h3>
                    <div class="small-muted">${esc(t.label || it.type)}${url ? ' · <a href="' + esc(url) + '" target="_blank" rel="noopener noreferrer">' + esc(url.replace(/^https?:\/\/(www\.)?/, '').replace(/\/.*$/, '')) + '</a>' : ''}</div>
                </div>
                <button class="btn btn-light btn-icon js-fav" title="Favorite">${it.favorite ? '<span class="text-warning">★</span>' : '☆'}</button>
                <button class="btn btn-light js-edit"><i data-feather="edit-2"></i> Edit</button>
            </div>`;
        if (it.type === 'login' || it.type === 'server') {
            html += this.field('Username', d.username);
            html += this.field('Password', d.password, { secret: true, extra: strengthHtml });
            if (d.totp) html += `<div class="vfield"><div class="grow"><div class="lbl">2FA code</div><div class="val totp-code" id="totpCode">······</div></div>
                <svg class="totp-ring" viewBox="0 0 36 36"><circle cx="18" cy="18" r="15" fill="none" stroke="var(--home-border)" stroke-width="4"/><circle id="totpArc" cx="18" cy="18" r="15" fill="none" stroke="#3b7ddd" stroke-width="4" stroke-dasharray="94.25" stroke-dashoffset="0" transform="rotate(-90 18 18)" stroke-linecap="round"/></svg>
                <button type="button" class="btn btn-light btn-icon js-copy-totp" title="Copy"><i data-feather="copy"></i></button></div>`;
            if (it.type === 'server') { html += this.field('Host', d.host); html += this.field('Port', d.port); }
            if (url) html += this.field('Website', d.url, { link: url, plain: true });
        } else if (it.type === 'card') {
            html += this.field('Name on card', d.cardholder, { plain: true });
            html += this.field('Number', (d.number || '').replace(/\s/g, '').replace(/(.{4})/g, '$1 ').trim(), { secret: true });
            html += this.field('Expires', d.exp);
            html += this.field('CVV', d.cvv, { secret: true });
            html += this.field('PIN', d.pin, { secret: true });
        } else if (it.type === 'wifi') {
            html += this.field('Network', d.ssid);
            html += this.field('Password', d.password, { secret: true, extra: strengthHtml });
            html += this.field('Security', d.security, { plain: true });
        }
        (d.fields || []).forEach(f => { html += this.field(f.label || 'Field', f.value, { secret: !!f.hidden }); });
        if (d.notes) html += `<div class="vfield"><div class="grow"><div class="lbl">Notes</div><div class="val plain" style="white-space:pre-wrap">${esc(d.notes)}</div></div></div>`;
        html += `<div class="small-muted mt-3 d-flex flex-wrap gap-3">
            <span>Updated ${esc(it.updated)}</span>
            ${it.changed ? '<span>Password changed ' + esc(it.changed) + '</span>' : ''}
            ${it.source ? '<span>Imported from ' + esc(it.source) + '</span>' : ''}
            ${it.history ? '<a href="#" class="js-history">Password history (' + it.history + ')</a>' : ''}
        </div><div id="historyBox"></div>
        <div class="mt-4 d-flex gap-2">
            ${url ? `<a href="${esc(url)}" target="_blank" rel="noopener noreferrer" class="btn btn-primary"><i data-feather="external-link"></i> Open site</a>` : ''}
            <button class="btn btn-outline-danger ms-auto js-delete"><i data-feather="trash-2"></i> Delete</button>
        </div>`;
        $('#vaultDetail').html(html);
        refeather();
        const $d = $('#vaultDetail');
        $d.find('.js-reveal').on('click', function () {
            const v = $(this).closest('.vfield').find('.val');
            if (v.data('shown')) { v.text('•'.repeat(12)).data('shown', 0); }
            else { v.text(v.data('secret')).data('shown', 1); }
        });
        $d.find('.js-copy').on('click', function () {
            copyText($(this).data('v'), $(this).data('l'));
            api('/app/Ajax/vault.php', { action: 'used', id: it.id }, { quiet: true });
            // Clear the clipboard after 60s if it still holds the secret
            const v = String($(this).data('v'));
            setTimeout(() => { if (navigator.clipboard && navigator.clipboard.readText) navigator.clipboard.readText().then(c => { if (c === v) navigator.clipboard.writeText(''); }).catch(() => {}); }, 60000);
        });
        $d.find('.js-edit').on('click', () => this.edit(it));
        $d.find('.js-back').on('click', e => { e.preventDefault(); $('#vaultShell').removeClass('show-detail'); });
        $d.find('.js-fav').on('click', () => api('/app/Ajax/vault.php', { action: 'favorite', id: it.id }).then(() => {
            it.favorite = it.favorite ? 0 : 1;
            const li = this.items.find(x => x.id === it.id); if (li) li.favorite = it.favorite;
            this.render(); this.detail(it);
        }));
        $d.find('.js-delete').on('click', () => {
            if (!confirm('Delete "' + d.name + '"? This can\'t be undone.')) return;
            api('/app/Ajax/vault.php', { action: 'delete', id: it.id }).then(() => {
                this.items = this.items.filter(x => x.id !== it.id); this.current = null; this.render();
                $('#vaultDetail').html('<div class="empty mt-5"><div class="big">🗑️</div>Deleted.</div>');
                $('#vaultShell').removeClass('show-detail');
            });
        });
        $d.find('.js-history').on('click', e => {
            e.preventDefault();
            api('/app/Ajax/vault.php', { action: 'history', id: it.id }).then(r => {
                $('#historyBox').html('<div class="mt-3">' + r.history.map(h => this.field('Before ' + h.when, h.password, { secret: true })).join('') + '</div>');
                refeather();
                $('#historyBox .js-reveal').on('click', function () { const v = $(this).closest('.vfield').find('.val'); v.text(v.data('shown') ? '•'.repeat(12) : v.data('secret')).data('shown', v.data('shown') ? 0 : 1); });
                $('#historyBox .js-copy').on('click', function () { copyText($(this).data('v'), 'Old password'); });
            });
        });
        if (d.totp) {
            const tick = () => totpNow(d.totp).then(c => {
                if (!c) { $('#totpCode').text('invalid key'); return; }
                $('#totpCode').text(c.code.replace(/(\d{3})(\d{3})/, '$1 $2'));
                $('#totpArc').attr('stroke-dashoffset', 94.25 * (1 - c.left / c.period)).attr('stroke', c.left <= 5 ? '#f0506e' : '#3b7ddd');
            });
            tick(); this.totpTimer = setInterval(tick, 1000);
            $d.find('.js-copy-totp').on('click', () => copyText($('#totpCode').text().replace(/\s/g, ''), '2FA code'));
        }
    },

    showFields(type) {
        $('#itemForm .f').addClass('hidden');
        $('#itemForm .f-' + type).removeClass('hidden');
    },

    addField(f) {
        f = f || { label: '', value: '', hidden: false };
        $('#customFields').append(`<div class="cf row g-2 mb-2">
            <div class="col-4"><input name="field_label[]" class="form-control form-control-sm" placeholder="Label" value="${esc(f.label)}"></div>
            <div class="col-5"><input name="field_value[]" class="form-control form-control-sm" placeholder="Value" value="${esc(f.value)}"></div>
            <div class="col-2"><select name="field_hidden[]" class="form-select form-select-sm" title="Hidden?"><option value="0">Text</option><option value="1" ${f.hidden ? 'selected' : ''}>Hidden</option></select></div>
            <div class="col-1 text-end"><button type="button" class="btn btn-sm btn-light js-del-field">×</button></div>
        </div>`);
    },

    edit(it, type) {
        const f = $('#itemForm')[0];
        f.reset();
        $('#customFields').empty();
        $(f).find('[name=password]').attr('type', 'password');
        if (it) {
            $('#itemTitle').text('Edit ' + it.d.name);
            f.id.value = it.id;
            f.type.value = it.type;
            Object.entries(it.d).forEach(([k, v]) => { if (f[k] && typeof v !== 'object') f[k].value = v; });
            f.folder_id.value = it.folder_id || '';
            f.favorite.checked = !!it.favorite;
            (it.d.fields || []).forEach(x => this.addField(x));
        } else {
            $('#itemTitle').text('New ' + ((this.types[type] || {}).label || 'item').toLowerCase());
            f.id.value = '';
            f.type.value = type || 'login';
            const folder = this.filter.startsWith('folder:') ? this.filter.slice(7) : '';
            if (folder && folder !== 'none') f.folder_id.value = folder;
            if ((type || 'login') !== 'note' && (type || 'login') !== 'card') { f.password.value = generatePassword(20); }
        }
        meter('#formMeter', f.password.value);
        this.showFields(f.type.value);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('itemModal')).show();
        setTimeout(() => f.name.focus(), 300);
    },

    newFolder(sel) {
        if (sel.value !== '__new') return;
        const name = prompt('Folder name');
        if (!name) { sel.value = ''; return; }
        api('/app/Ajax/vault.php', { action: 'folder', name }).then(r => {
            $(sel).find('option[value="__new"]').before(`<option value="${r.id}">${esc(name)}</option>`);
            sel.value = r.id;
        });
    },

    save() {
        const fd = new FormData($('#itemForm')[0]);
        api('/app/Ajax/vault.php', fd).then(r => {
            bootstrap.Modal.getInstance(document.getElementById('itemModal')).hide();
            ShowAlert('Saved', 'success');
            setTimeout(() => { window.location = '/vault?item=' + r.id; }, 250);
        });
    },

    autoLock() {
        let left = this.secondsLeft;
        const show = () => {
            const m = Math.floor(left / 60);
            $('#lockIn').text(left > 0 ? 'locks in ' + (m ? m + ' min' : left + ' s') + ' without activity' : 'locked');
        };
        show();
        setInterval(() => { left--; show(); if (left <= 0) window.location = '/vault'; }, 1000);
        // any click or keypress in the vault counts as activity
        let last = 0;
        $(document).on('click keydown', () => {
            if (Date.now() - last < 30000) return;
            last = Date.now();
            api('/app/Ajax/vault.php', { action: 'ping' }, { quiet: true }).then(r => { left = r.seconds_left; });
        });
        // Re-check (server is the source of truth)
        setInterval(() => api('/app/Ajax/vault.php', { action: 'status' }, { quiet: true }).then(r => { if (!r.unlocked) window.location = '/vault'; else left = r.seconds_left; }), 60000);
    }
};
