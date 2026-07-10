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
    let currentAdminName = sessionStorage.getItem('braincare_admin_name');

    function showLoggedIn(show) {
        document.getElementById('screen-login').style.display = show ? 'none' : 'flex';
        document.getElementById('screen-admin').style.display = show ? 'grid' : 'none';
        if (show) {
            document.getElementById('admin-current-name').textContent = currentAdminName || '';
        }
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
        sessionStorage.removeItem('braincare_admin_name');
        token = null;
        currentAdminName = null;
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
            currentAdminName = body.user.name;
            sessionStorage.setItem('braincare_admin_token', token);
            sessionStorage.setItem('braincare_admin_name', currentAdminName);
            showLoggedIn(true);
            loadTab('stats');
        } catch (e) {
            errorEl.textContent = '通信エラーが発生しました';
            errorEl.style.display = 'block';
        }
    });

    document.getElementById('btn-logout').addEventListener('click', () => {
        sessionStorage.removeItem('braincare_admin_token');
        sessionStorage.removeItem('braincare_admin_name');
        token = null;
        currentAdminName = null;
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
        if (tab === 'messages') loadMessagesTab();
        if (tab === 'import_users') resetImportUsersTab();
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

    // 苦手分野を6分野同時に見比べられるレーダーチャート（常に固定の並び順で描画する）
    const RADAR_DOMAIN_ORDER = ['calc', 'memory', 'number_order', 'word_scramble', 'true_false', 'spot_difference'];

    function drawRadarChart(canvas, byGameType) {
        const ctx = canvas.getContext('2d');
        const w = canvas.width;
        const h = canvas.height;
        ctx.clearRect(0, 0, w, h);

        const dataMap = {};
        byGameType.forEach((r) => { dataMap[r.game_type] = Number(r.accuracy_percent); });

        const n = RADAR_DOMAIN_ORDER.length;
        const angleStep = (Math.PI * 2) / n;
        const cx = w / 2;
        const cy = h / 2 - 8;
        const radius = Math.min(w, h) / 2 - 70;
        const angleFor = (i) => -Math.PI / 2 + i * angleStep;

        // 目盛りの同心多角形(25/50/75/100%)
        ctx.strokeStyle = '#e3d9c6';
        ctx.lineWidth = 1;
        [0.25, 0.5, 0.75, 1].forEach((frac) => {
            ctx.beginPath();
            for (let i = 0; i <= n; i++) {
                const angle = angleFor(i % n);
                const x = cx + radius * frac * Math.cos(angle);
                const y = cy + radius * frac * Math.sin(angle);
                if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
            }
            ctx.stroke();
        });

        // 軸線とラベル
        ctx.strokeStyle = '#cabf9e';
        ctx.fillStyle = '#3a3244';
        ctx.font = '14px sans-serif';
        ctx.textAlign = 'center';
        RADAR_DOMAIN_ORDER.forEach((type, i) => {
            const angle = angleFor(i);
            const x = cx + radius * Math.cos(angle);
            const y = cy + radius * Math.sin(angle);
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.lineTo(x, y);
            ctx.stroke();
            const lx = cx + (radius + 34) * Math.cos(angle);
            const ly = cy + (radius + 34) * Math.sin(angle);
            ctx.fillText(GAME_LABELS[type] || type, lx, ly);
        });

        // データの多角形
        ctx.beginPath();
        RADAR_DOMAIN_ORDER.forEach((type, i) => {
            const value = dataMap[type] ?? 0;
            const angle = angleFor(i);
            const r = radius * (value / 100);
            const x = cx + r * Math.cos(angle);
            const y = cy + r * Math.sin(angle);
            if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        });
        ctx.closePath();
        ctx.fillStyle = 'rgba(217, 119, 87, 0.25)';
        ctx.strokeStyle = '#d97757';
        ctx.lineWidth = 2;
        ctx.fill();
        ctx.stroke();

        // 各分野の頂点と正答率の数値
        RADAR_DOMAIN_ORDER.forEach((type, i) => {
            const value = dataMap[type] ?? 0;
            const angle = angleFor(i);
            const r = radius * (value / 100);
            const x = cx + r * Math.cos(angle);
            const y = cy + r * Math.sin(angle);
            ctx.beginPath();
            ctx.arc(x, y, 4, 0, Math.PI * 2);
            ctx.fillStyle = accuracyColor(value);
            ctx.fill();

            ctx.fillStyle = '#6b7089';
            ctx.font = '12px sans-serif';
            const labelR = radius * (value / 100) + 14;
            const lx2 = cx + labelR * Math.cos(angle);
            const ly2 = cy + labelR * Math.sin(angle);
            ctx.fillText(dataMap[type] !== undefined ? `${Math.round(value)}%` : '未プレイ', lx2, ly2);
        });
    }

    function sourceTag(source) {
        if (source === 'test') return '（テスト）';
        if (source === 'battle') return '（対戦）';
        return '';
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
                <td><input type="checkbox" class="user-select-checkbox" value="${u.id}" data-user-name="${escapeHtml(u.name)}"></td>
                <td>${u.id}</td>
                <td>${escapeHtml(u.name)}</td>
                <td>${u.birthday || '-'}</td>
                <td>${u.role === 'admin' ? '管理者' : '利用者'}</td>
                <td>${u.created_at}</td>
                <td><button class="btn btn-outline" data-view-user="${u.id}" style="padding:8px 14px;font-size:0.95rem;min-height:auto;">個人の記録を見る</button></td>
                <td><button class="btn btn-outline" data-edit-user="${u.id}" data-user-name="${escapeHtml(u.name)}" data-user-birthday="${u.birthday || ''}" data-user-role="${u.role}" style="padding:8px 14px;font-size:0.95rem;min-height:auto;">編集</button></td>
                <td><button class="btn btn-danger" data-delete-user="${u.id}" data-user-name="${escapeHtml(u.name)}" style="padding:8px 14px;font-size:0.95rem;min-height:auto;">削除</button></td>
            </tr>
        `).join('');
        document.getElementById('users-select-all').checked = false;
        updateBulkDeleteButtonState();
    }

    function updateBulkDeleteButtonState() {
        const selected = document.querySelectorAll('#users-table .user-select-checkbox:checked').length;
        const bulkBtn = document.getElementById('btn-bulk-delete-users');
        bulkBtn.disabled = selected === 0;
        bulkBtn.textContent = selected > 0 ? `選択した利用者を削除（${selected}名）` : '選択した利用者を削除';
    }

    document.getElementById('users-select-all').addEventListener('change', (ev) => {
        document.querySelectorAll('#users-table .user-select-checkbox').forEach((cb) => {
            cb.checked = ev.target.checked;
        });
        updateBulkDeleteButtonState();
    });

    document.querySelector('#users-table tbody').addEventListener('change', (ev) => {
        if (ev.target.classList.contains('user-select-checkbox')) {
            updateBulkDeleteButtonState();
        }
    });

    document.getElementById('btn-bulk-delete-users').addEventListener('click', async () => {
        const checked = Array.from(document.querySelectorAll('#users-table .user-select-checkbox:checked'));
        if (checked.length === 0) return;
        const names = checked.map((cb) => cb.dataset.userName).join('、');
        if (!confirm(`選択した${checked.length}名（${names}）を削除します。学習履歴・対戦履歴・ランキングもすべて削除され、元に戻せません。よろしいですか？`)) {
            return;
        }
        let successCount = 0;
        const failures = [];
        for (const cb of checked) {
            const { ok, body } = await apiPost('delete_user', { user_id: Number(cb.value) });
            if (ok) {
                successCount++;
            } else {
                failures.push(`${cb.dataset.userName}（${body.error || '失敗'}）`);
            }
        }
        if (failures.length > 0) {
            alert(`${successCount}名を削除しました。以下は削除できませんでした:\n${failures.join('\n')}`);
        }
        loadUsers();
    });

    document.querySelector('#users-table tbody').addEventListener('click', (ev) => {
        const viewBtn = ev.target.closest('button[data-view-user]');
        if (viewBtn) {
            loadUserDetail(Number(viewBtn.dataset.viewUser));
            return;
        }
        const editBtn = ev.target.closest('button[data-edit-user]');
        if (editBtn) {
            openEditUser({
                id: Number(editBtn.dataset.editUser),
                name: editBtn.dataset.userName,
                birthday: editBtn.dataset.userBirthday,
                role: editBtn.dataset.userRole,
            });
            return;
        }
        const delBtn = ev.target.closest('button[data-delete-user]');
        if (delBtn) {
            deleteUser(Number(delBtn.dataset.deleteUser), delBtn.dataset.userName);
        }
    });

    async function deleteUser(userId, userName) {
        if (!confirm(`「${userName}」さんを削除します。学習履歴・対戦履歴・ランキングもすべて削除され、元に戻せません。よろしいですか？`)) {
            return;
        }
        const { ok, body } = await apiPost('delete_user', { user_id: userId });
        if (!ok) {
            alert(body.error || '削除に失敗しました');
            return;
        }
        loadUsers();
    }

    // ------- 利用者個人の記録（管理者による閲覧） -------
    document.getElementById('btn-user-detail-back').addEventListener('click', () => {
        showTabPanel('users');
    });

    // ------- プレイカレンダー -------
    const userDetailCalendarState = (() => {
        const now = new Date();
        return { year: now.getFullYear(), month: now.getMonth(), playDates: [] };
    })();

    function renderUserDetailCalendar() {
        const playDates = new Set(userDetailCalendarState.playDates);
        const { year, month } = userDetailCalendarState;
        document.getElementById('user-detail-cal-label').textContent = `${year}年${month + 1}月`;

        const firstWeekday = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const todayStr = new Date().toISOString().slice(0, 10);

        let html = '<div class="calendar-weekdays">' + ['日', '月', '火', '水', '木', '金', '土'].map((d) => `<span>${d}</span>`).join('') + '</div>';
        html += '<div class="calendar-days">';
        for (let i = 0; i < firstWeekday; i++) html += '<span></span>';
        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const cls = ['cal-day', playDates.has(dateStr) ? 'played' : '', dateStr === todayStr ? 'today' : ''].join(' ').trim();
            html += `<span class="${cls}">${day}</span>`;
        }
        html += '</div>';
        document.getElementById('user-detail-calendar').innerHTML = html;
    }

    document.getElementById('user-detail-cal-prev').addEventListener('click', () => {
        userDetailCalendarState.month--;
        if (userDetailCalendarState.month < 0) { userDetailCalendarState.month = 11; userDetailCalendarState.year--; }
        renderUserDetailCalendar();
    });
    document.getElementById('user-detail-cal-next').addEventListener('click', () => {
        userDetailCalendarState.month++;
        if (userDetailCalendarState.month > 11) { userDetailCalendarState.month = 0; userDetailCalendarState.year++; }
        renderUserDetailCalendar();
    });

    let currentUserDetailId = null;

    async function loadUserDetail(userId) {
        currentUserDetailId = userId;
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

        drawRadarChart(
            document.getElementById('user-detail-chart'),
            data.by_game_type
        );

        const now = new Date();
        userDetailCalendarState.year = now.getFullYear();
        userDetailCalendarState.month = now.getMonth();
        userDetailCalendarState.playDates = data.play_dates;
        renderUserDetailCalendar();

        tbody.innerHTML = data.history.map((h) => `
            <tr>
                <td>${h.created_at}</td>
                <td>${GAME_LABELS[h.game_type] || h.game_type}${sourceTag(h.source)}</td>
                <td>${h.score}</td>
                <td>${h.correct} / ${h.total_rounds}</td>
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

    // ------- 利用者一斉登録（CSV） -------
    function resetImportUsersTab() {
        document.getElementById('import-csv-file').value = '';
        document.getElementById('import-error').style.display = 'none';
        document.getElementById('import-result').style.display = 'none';
    }

    document.getElementById('btn-download-import-sample').addEventListener('click', () => {
        const csv = '\uFEFF名前,生年月日,権限\n山田 太郎,1945-04-01,\n鈴木 花子,,\n';
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'braincare_users_sample.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    });

    document.getElementById('btn-import-users').addEventListener('click', async () => {
        const fileInput = document.getElementById('import-csv-file');
        const errorEl = document.getElementById('import-error');
        errorEl.style.display = 'none';

        if (!fileInput.files || fileInput.files.length === 0) {
            errorEl.textContent = 'CSVファイルを選択してください';
            errorEl.style.display = 'block';
            return;
        }

        const formData = new FormData();
        formData.append('csv', fileInput.files[0]);

        const res = await fetch('php/admin.php?action=import_users', {
            method: 'POST',
            headers: { Authorization: `Bearer ${token}` },
            body: formData,
        });
        if (res.status === 401 || res.status === 403) {
            handleAuthFailure();
            return;
        }
        const body = await res.json();
        if (!res.ok) {
            errorEl.textContent = body.error || '取り込みに失敗しました';
            errorEl.style.display = 'block';
            return;
        }

        const resultEl = document.getElementById('import-result');
        const titleEl = document.getElementById('import-result-title');
        const tbody = document.querySelector('#import-result-table tbody');
        titleEl.textContent = `登録 ${body.created.length}件 / スキップ ${body.skipped.length}件`;
        tbody.innerHTML = [
            ...body.created.map((r) => `
                <tr>
                    <td>${r.row}</td>
                    <td>${escapeHtml(r.name)}</td>
                    <td style="color:#16855a;font-weight:700;">登録しました</td>
                </tr>
            `),
            ...body.skipped.map((r) => `
                <tr>
                    <td>${r.row}</td>
                    <td>${escapeHtml(r.name)}</td>
                    <td class="error-text">${escapeHtml(r.reason)}</td>
                </tr>
            `),
        ].join('') || '<tr><td colspan="3" class="hint-text">対象データがありませんでした</td></tr>';
        resultEl.style.display = 'block';
        fileInput.value = '';
        loadUsers();
    });

    // ------- 利用者情報の編集 -------
    let editingUserId = null;
    const euPasswordField = document.getElementById('eu-password');
    document.getElementById('eu-role').addEventListener('change', (ev) => {
        euPasswordField.style.display = ev.target.value === 'admin' ? 'block' : 'none';
    });

    function openEditUser(user) {
        editingUserId = user.id;
        document.getElementById('eu-name').value = user.name;
        document.getElementById('eu-birthday').value = user.birthday || '';
        document.getElementById('eu-role').value = user.role;
        euPasswordField.style.display = user.role === 'admin' ? 'block' : 'none';
        euPasswordField.value = '';
        euPasswordField.placeholder = user.role === 'admin' ? '新しいパスワード（変更する場合のみ入力）' : '';
        document.getElementById('eu-error').style.display = 'none';
        document.getElementById('eu-success').style.display = 'none';
        showTabPanel('edit_user');
    }

    document.getElementById('btn-edit-user-back').addEventListener('click', () => showTabPanel('users'));

    document.getElementById('btn-update-user').addEventListener('click', async () => {
        const name = document.getElementById('eu-name').value.trim();
        const password = euPasswordField.value;
        const birthday = document.getElementById('eu-birthday').value;
        const role = document.getElementById('eu-role').value;
        const errorEl = document.getElementById('eu-error');
        const successEl = document.getElementById('eu-success');
        errorEl.style.display = 'none';
        successEl.style.display = 'none';

        const { ok, body } = await apiPost('update_user', { user_id: editingUserId, name, password, birthday, role });
        if (!ok) {
            errorEl.textContent = body.error || '更新に失敗しました';
            errorEl.style.display = 'block';
            return;
        }
        successEl.style.display = 'block';
        loadUsers();
    });

    // ------- 学習履歴 -------
    async function loadHistory() {
        const data = await apiGet('history');
        const tbody = document.querySelector('#history-table tbody');
        tbody.innerHTML = data.history.map((h) => `
            <tr>
                <td>${h.created_at}</td>
                <td>${escapeHtml(h.user_name)}</td>
                <td>${GAME_LABELS[h.game_type] || h.game_type}${sourceTag(h.source)}</td>
                <td>${h.score}</td>
                <td>${h.correct} / ${h.total_rounds}</td>
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

    // ------- メッセージ送信 -------
    async function loadMessagesTab() {
        const select = document.getElementById('msg-target-select');
        const data = await apiGet('users');
        select.innerHTML = '<option value="">全員へ一斉送信</option>' + data.users
            .filter((u) => u.role === 'user')
            .map((u) => `<option value="${u.id}">${escapeHtml(u.name)}さんへ</option>`)
            .join('');
        loadSentMessages();
    }

    async function loadSentMessages() {
        const data = await apiGet('sent_messages');
        const tbody = document.querySelector('#messages-table tbody');
        tbody.innerHTML = data.messages.map((m) => `
            <tr>
                <td>${m.created_at}</td>
                <td>${m.user_id ? escapeHtml(m.target_name) + 'さん' : '全員'}</td>
                <td>${escapeHtml(m.sender_name)}</td>
                <td>${escapeHtml(m.body)}</td>
                <td><button class="btn btn-danger" data-delete-message="${m.id}" style="padding:8px 14px;font-size:0.95rem;min-height:auto;">取り消し</button></td>
            </tr>
        `).join('') || '<tr><td colspan="5" class="hint-text">まだメッセージがありません</td></tr>';
    }

    document.querySelector('#messages-table tbody').addEventListener('click', async (ev) => {
        const btn = ev.target.closest('button[data-delete-message]');
        if (!btn) return;
        if (!confirm('このメッセージを取り消します。利用者側の画面にも表示されなくなります。よろしいですか？')) {
            return;
        }
        const { ok, body } = await apiPost('delete_message', { id: Number(btn.dataset.deleteMessage) });
        if (!ok) {
            alert(body.error || '取り消しに失敗しました');
            return;
        }
        loadSentMessages();
    });

    document.getElementById('btn-send-message').addEventListener('click', async () => {
        const userId = document.getElementById('msg-target-select').value;
        const text = document.getElementById('msg-body-input').value.trim();
        const errorEl = document.getElementById('msg-error');
        const successEl = document.getElementById('msg-success');
        errorEl.style.display = 'none';
        successEl.style.display = 'none';

        const { ok, body } = await apiPost('send_message', { user_id: userId, body: text });
        if (!ok) {
            errorEl.textContent = body.error || '送信に失敗しました';
            errorEl.style.display = 'block';
            return;
        }
        successEl.style.display = 'block';
        document.getElementById('msg-body-input').value = '';
        loadSentMessages();
    });

    // ------- CSVダウンロード -------
    async function downloadHistoryCsv(userId) {
        const qs = userId ? `&user_id=${encodeURIComponent(userId)}` : '';
        const res = await fetch(`php/admin.php?action=export_history_csv${qs}`, {
            headers: { Authorization: `Bearer ${token}` },
        });
        if (!res.ok) {
            alert('CSVの取得に失敗しました');
            return;
        }
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'learning_history.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    document.getElementById('btn-export-history-csv').addEventListener('click', () => downloadHistoryCsv(null));
    document.getElementById('btn-export-user-csv').addEventListener('click', () => downloadHistoryCsv(currentUserDetailId));

    document.getElementById('btn-export-user-pdf').addEventListener('click', () => {
        const now = new Date();
        document.getElementById('user-detail-print-meta').textContent = `出力日時: ${now.toLocaleString('ja-JP')}（出力者: ${currentAdminName || ''}）`;
        window.print();
    });

    // ------- 初期化 -------
    if (token) {
        showLoggedIn(true);
        loadTab('stats');
    } else {
        showLoggedIn(false);
    }
})();
