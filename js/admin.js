(() => {
    'use strict';

    const GAME_LABELS = {
        calc: '計算問題',
        memory: '記憶ゲーム',
        number_order: '数字順タッチ',
        word_scramble: '文字並べ替え',
        true_false: '○×クイズ',
        spot_difference: '間違い探し',
    };

    let token = sessionStorage.getItem('braincare_admin_token');

    function showLoggedIn(show) {
        document.getElementById('screen-login').style.display = show ? 'none' : 'flex';
        document.getElementById('screen-admin').style.display = show ? 'grid' : 'none';
    }

    async function apiGet(action, params = {}) {
        const qs = new URLSearchParams({ action, ...params }).toString();
        const res = await fetch(`php/admin.php?${qs}`, {
            headers: { Authorization: `Bearer ${token}` },
        });
        if (res.status === 401 || res.status === 403) {
            handleAuthFailure();
            throw new Error('認証エラー');
        }
        return res.json();
    }

    async function apiPost(action, body) {
        const res = await fetch(`php/admin.php?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
            body: JSON.stringify(body),
        });
        if (res.status === 401 || res.status === 403) {
            handleAuthFailure();
            throw new Error('認証エラー');
        }
        return { ok: res.ok, body: await res.json() };
    }

    function handleAuthFailure() {
        sessionStorage.removeItem('braincare_admin_token');
        token = null;
        showLoggedIn(false);
    }

    // ------- ログイン -------
    document.getElementById('btn-login').addEventListener('click', async () => {
        const name = document.getElementById('login-name').value.trim();
        const password = document.getElementById('login-password').value;
        const errorEl = document.getElementById('login-error');
        errorEl.style.display = 'none';

        try {
            const res = await fetch('php/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, password }),
            });
            const body = await res.json();
            if (!res.ok) {
                errorEl.textContent = body.error || 'ログインに失敗しました';
                errorEl.style.display = 'block';
                return;
            }
            if (body.user.role !== 'admin') {
                errorEl.textContent = '管理者権限を持つアカウントでログインしてください';
                errorEl.style.display = 'block';
                return;
            }
            token = body.token;
            sessionStorage.setItem('braincare_admin_token', token);
            showLoggedIn(true);
            loadTab('stats');
        } catch (e) {
            errorEl.textContent = '通信エラーが発生しました';
            errorEl.style.display = 'block';
        }
    });

    document.getElementById('btn-logout').addEventListener('click', () => {
        sessionStorage.removeItem('braincare_admin_token');
        token = null;
        showLoggedIn(false);
    });

    // ------- タブ切り替え -------
    function showTabPanel(tab) {
        document.querySelectorAll('.admin-nav button[data-tab]').forEach((b) => {
            b.classList.toggle('active', b.dataset.tab === tab);
        });
        document.querySelectorAll('.tab-panel').forEach((p) => { p.style.display = 'none'; });
        document.getElementById(`tab-${tab}`).style.display = 'block';
    }

    document.querySelectorAll('.admin-nav button[data-tab]').forEach((btn) => {
        btn.addEventListener('click', () => {
            showTabPanel(btn.dataset.tab);
            loadTab(btn.dataset.tab);
        });
    });

    function loadTab(tab) {
        if (tab === 'stats') loadStats();
        if (tab === 'users') loadUsers();
        if (tab === 'history') loadHistory();
        if (tab === 'battles') loadBattles();
        if (tab === 'ranking') loadRanking();
    }

    // ------- 概要・統計 -------
    async function loadStats() {
        const data = await apiGet('stats');
        const tiles = document.getElementById('stat-tiles');
        tiles.innerHTML = `
            <div class="stat-tile"><div class="value">${data.total_users}</div><div class="label">利用者数</div></div>
            <div class="stat-tile"><div class="value">${data.total_plays}</div><div class="label">ソロプレイ回数</div></div>
            <div class="stat-tile"><div class="value">${data.total_battles}</div><div class="label">対戦回数</div></div>
        `;
        drawBarChart(
            document.getElementById('chart-game-type'),
            data.by_game_type.map((r) => ({ label: GAME_LABELS[r.game_type] || r.game_type, value: Number(r.plays) }))
        );
        drawBarChart(
            document.getElementById('chart-by-day'),
            data.by_day.map((r) => ({ label: r.day.slice(5), value: Number(r.plays) }))
        );
    }

    // 正答率に応じて弱点(赤)〜得意(緑)の色を付ける
    function accuracyColor(percent) {
        if (percent < 50) return '#c94f5f';
        if (percent < 80) return '#d3a13f';
        return '#3a9d72';
    }

    function weaknessChartItems(byGameType) {
        return byGameType.map((r) => ({
            label: GAME_LABELS[r.game_type] || r.game_type,
            value: Number(r.accuracy_percent),
            valueLabel: `${Math.round(Number(r.accuracy_percent))}%`,
            color: accuracyColor(Number(r.accuracy_percent)),
        }));
    }

    function drawBarChart(canvas, items, options = {}) {
        const ctx = canvas.getContext('2d');
        const w = canvas.width;
        const h = canvas.height;
        ctx.clearRect(0, 0, w, h);
        if (items.length === 0) {
            ctx.fillStyle = '#9a9fb5';
            ctx.font = '16px sans-serif';
            ctx.fillText('まだデータがありません', 16, h / 2);
            return;
        }

        const max = options.max || Math.max(1, ...items.map((i) => i.value));
        const padding = 30;
        const barGap = 12;
        const barWidth = (w - padding * 2) / items.length - barGap;

        ctx.font = '12px sans-serif';

        items.forEach((item, i) => {
            const x = padding + i * (barWidth + barGap);
            const barHeight = (h - padding * 2) * (item.value / max);
            const y = h - padding - barHeight;

            ctx.fillStyle = item.color || '#4a8fb0';
            ctx.fillRect(x, y, barWidth, barHeight);

            ctx.fillStyle = '#3a3f55';
            ctx.textAlign = 'center';
            ctx.fillText(item.valueLabel ?? String(item.value), x + barWidth / 2, y - 6);

            ctx.fillStyle = '#6b7089';
            ctx.fillText(item.label, x + barWidth / 2, h - padding + 16);
        });
    }

    // ------- 利用者一覧 -------
    async function loadUsers() {
        const data = await apiGet('users');
        const tbody = document.querySelector('#users-table tbody');
        tbody.innerHTML = data.users.map((u) => `
            <tr>
                <td>${u.id}</td>
                <td>${escapeHtml(u.name)}</td>
                <td>${u.birthday || '-'}</td>
                <td>${u.role === 'admin' ? '管理者' : '利用者'}</td>
                <td>${u.created_at}</td>
                <td><button class="btn btn-outline" data-view-user="${u.id}" style="padding:8px 14px;font-size:0.95rem;min-height:auto;">個人の記録を見る</button></td>
            </tr>
        `).join('');
    }

    document.querySelector('#users-table tbody').addEventListener('click', (ev) => {
        const btn = ev.target.closest('button[data-view-user]');
        if (btn) loadUserDetail(Number(btn.dataset.viewUser));
    });

    // ------- 利用者個人の記録（管理者による閲覧） -------
    document.getElementById('btn-user-detail-back').addEventListener('click', () => {
        showTabPanel('users');
    });

    async function loadUserDetail(userId) {
        showTabPanel('user_detail');
        const tiles = document.getElementById('user-detail-stat-tiles');
        const tbody = document.querySelector('#user-detail-history-table tbody');
        document.getElementById('user-detail-name').textContent = '読み込み中…';
        tiles.innerHTML = '';
        tbody.innerHTML = '';

        const data = await apiGet('user_summary', { user_id: userId });

        document.getElementById('user-detail-name').textContent = `${data.user.name} さんの記録`;

        const rankText = data.ranking.position
            ? `${data.ranking.position} / ${data.ranking.total_ranked} 位`
            : '対戦未経験';

        document.getElementById('user-detail-badge-list').innerHTML = data.badges.map((b) => `
            <div class="badge-tile ${b.earned ? '' : 'locked'}">
                <span class="emoji">${b.emoji}</span>
                <div class="label">${b.label}</div>
            </div>
        `).join('');

        document.getElementById('user-detail-goal-percent').textContent = `達成率 ${data.daily_goals.percent}%`;
        document.getElementById('user-detail-goal-list').innerHTML = data.daily_goals.goals.map((g) => `
            <li class="goal-item ${g.done ? 'done' : ''}">
                <span class="check">${g.done ? '☑' : '☐'}</span><span>${g.label}</span>
            </li>
        `).join('');

        tiles.innerHTML = `
            <div class="stat-tile"><div class="value">${data.totals.plays}</div><div class="label">プレイ回数</div></div>
            <div class="stat-tile"><div class="value">${data.totals.avg_score}</div><div class="label">平均スコア</div></div>
            <div class="stat-tile"><div class="value">${data.totals.best_score}</div><div class="label">最高スコア</div></div>
            <div class="stat-tile"><div class="value">${data.streak_days}日</div><div class="label">連続プレイ日数</div></div>
            <div class="stat-tile"><div class="value">${data.ranking.win}勝${data.ranking.lose}敗</div><div class="label">対戦成績</div></div>
            <div class="stat-tile"><div class="value">${rankText}</div><div class="label">対戦ランキング</div></div>
        `;

        drawBarChart(
            document.getElementById('user-detail-chart'),
            weaknessChartItems(data.by_game_type),
            { max: 100 }
        );

        tbody.innerHTML = data.history.map((h) => `
            <tr>
                <td>${h.created_at}</td>
                <td>${GAME_LABELS[h.game_type] || h.game_type}</td>
                <td>${h.score}</td>
                <td>${h.correct}</td>
            </tr>
        `).join('') || '<tr><td colspan="4" class="hint-text">まだプレイ履歴がありません</td></tr>';
    }

    // ------- 利用者登録 -------
    const cuPasswordField = document.getElementById('cu-password');
    document.getElementById('cu-role').addEventListener('change', (ev) => {
        cuPasswordField.style.display = ev.target.value === 'admin' ? 'block' : 'none';
    });

    document.getElementById('btn-create-user').addEventListener('click', async () => {
        const name = document.getElementById('cu-name').value.trim();
        const password = document.getElementById('cu-password').value;
        const birthday = document.getElementById('cu-birthday').value;
        const role = document.getElementById('cu-role').value;
        const errorEl = document.getElementById('cu-error');
        const successEl = document.getElementById('cu-success');
        errorEl.style.display = 'none';
        successEl.style.display = 'none';

        const { ok, body } = await apiPost('create_user', { name, password, birthday, role });
        if (!ok) {
            errorEl.textContent = body.error || '登録に失敗しました';
            errorEl.style.display = 'block';
            return;
        }
        successEl.style.display = 'block';
        document.getElementById('cu-name').value = '';
        document.getElementById('cu-password').value = '';
        document.getElementById('cu-birthday').value = '';
    });

    // ------- 学習履歴 -------
    async function loadHistory() {
        const data = await apiGet('history');
        const tbody = document.querySelector('#history-table tbody');
        tbody.innerHTML = data.history.map((h) => `
            <tr>
                <td>${h.created_at}</td>
                <td>${escapeHtml(h.user_name)}</td>
                <td>${GAME_LABELS[h.game_type] || h.game_type}</td>
                <td>${h.score}</td>
                <td>${h.correct}</td>
                <td>${h.play_time}</td>
            </tr>
        `).join('');
    }

    // ------- 対戦履歴 -------
    async function loadBattles() {
        const data = await apiGet('battles');
        const tbody = document.querySelector('#battles-table tbody');
        tbody.innerHTML = data.battles.map((b) => `
            <tr>
                <td>${b.created_at}</td>
                <td>${escapeHtml(b.player1_name)}</td>
                <td>${escapeHtml(b.player2_name)}</td>
                <td>${b.score1} - ${b.score2}</td>
                <td>${b.winner_name ? escapeHtml(b.winner_name) : '引き分け'}</td>
                <td>${GAME_LABELS[b.game_type] || b.game_type}</td>
            </tr>
        `).join('');
    }

    // ------- ランキング -------
    async function loadRanking() {
        const data = await apiGet('ranking');
        const tbody = document.querySelector('#ranking-table tbody');
        tbody.innerHTML = data.ranking.map((r, i) => `
            <tr>
                <td>${i + 1}</td>
                <td>${escapeHtml(r.name)}</td>
                <td>${r.point}</td>
                <td>${r.win}</td>
                <td>${r.lose}</td>
            </tr>
        `).join('');
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    // ------- 初期化 -------
    if (token) {
        showLoggedIn(true);
        loadTab('stats');
    } else {
        showLoggedIn(false);
    }
})();
