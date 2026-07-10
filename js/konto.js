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
    const GAME_TYPES = Object.keys(GAME_LABELS);

    const WS_URL = document.querySelector('meta[name="braincare-ws-url"]')?.content || 'ws://localhost:8080';

    const screens = {
        login: document.getElementById('screen-login'),
        menu: document.getElementById('screen-menu'),
        soloMenu: document.getElementById('screen-solo-menu'),
        join: document.getElementById('screen-join'),
        waiting: document.getElementById('screen-waiting'),
        game: document.getElementById('screen-game'),
        gameover: document.getElementById('screen-gameover'),
        mypage: document.getElementById('screen-mypage'),
    };

    function showScreen(name) {
        Object.entries(screens).forEach(([key, el]) => {
            el.style.display = key === name ? 'flex' : 'none';
        });
    }

    let token = sessionStorage.getItem('braincare_token');
    let user = JSON.parse(sessionStorage.getItem('braincare_user') || 'null');
    let screenId = sessionStorage.getItem('braincare_screen') || '1';

    let ws = null;
    let answerBuffer = null; // 入力中の回答を保持する汎用バッファ

    // ------- 音声案内 -------
    let voiceEnabled = localStorage.getItem('braincare_voice_enabled') !== 'false';

    function updateVoiceToggleUI() {
        const el = document.getElementById('voice-toggle-state');
        if (el) el.textContent = voiceEnabled ? 'ON' : 'OFF';
    }

    function speak(text) {
        if (!voiceEnabled || !text || !('speechSynthesis' in window)) return;
        window.speechSynthesis.cancel();
        const utter = new SpeechSynthesisUtterance(text);
        utter.lang = 'ja-JP';
        utter.rate = 0.95;
        window.speechSynthesis.speak(utter);
    }

    document.getElementById('btn-voice-toggle').addEventListener('click', () => {
        voiceEnabled = !voiceEnabled;
        localStorage.setItem('braincare_voice_enabled', voiceEnabled ? 'true' : 'false');
        updateVoiceToggleUI();
        if (voiceEnabled) speak('音声案内をオンにしました');
    });
    updateVoiceToggleUI();

    function connectWs() {
        ws = new WebSocket(WS_URL);
        ws.addEventListener('open', () => {
            ws.send(JSON.stringify({ type: 'hello', role: 'konto', token, screen_id: screenId }));
        });
        ws.addEventListener('message', (ev) => {
            let data;
            try {
                data = JSON.parse(ev.data);
            } catch (e) {
                return;
            }
            handleServerMessage(data);
        });
        ws.addEventListener('close', () => {
            // 予期しない切断時はログイン画面に戻す
            if (screens.game.style.display !== 'none' || screens.waiting.style.display !== 'none') {
                showMenu();
            }
        });
    }

    function handleServerMessage(data) {
        switch (data.type) {
            case 'hello_ack':
                showMenu();
                break;
            case 'error':
                if (screens.join.style.display !== 'none') {
                    const errorEl = document.getElementById('join-error');
                    errorEl.textContent = data.message || 'エラーが発生しました';
                    errorEl.style.display = 'block';
                } else {
                    alert(data.message || 'エラーが発生しました');
                }
                break;
            case 'battle_waiting':
                screens.waiting.querySelector('#waiting-title').textContent = 'もう一人の参加をお待ちください…';
                screens.waiting.querySelector('#waiting-detail').textContent = '';
                showScreen('waiting');
                break;
            case 'battle_matched':
                screens.waiting.querySelector('#waiting-title').textContent = data.opponent_name + 'さんと対戦開始！';
                screens.waiting.querySelector('#waiting-detail').textContent = '';
                break;
            case 'question':
                renderQuestion(data);
                break;
            case 'result':
                renderResult(data);
                break;
            case 'game_over':
                renderGameOver(data);
                break;
        }
    }

    // ------- ログイン（名前選択・パスワードなし） -------
    async function loadNameList() {
        const listEl = document.getElementById('name-list');
        const errorEl = document.getElementById('login-error');
        errorEl.style.display = 'none';
        listEl.innerHTML = '<p class="hint-text">読み込み中…</p>';

        try {
            const res = await fetch('php/users_public.php');
            const body = await res.json();
            listEl.innerHTML = '';
            (body.users || []).forEach((u) => {
                const btn = document.createElement('button');
                btn.className = 'btn';
                btn.textContent = u.name;
                btn.addEventListener('click', () => loginAsUser(u.id));
                listEl.appendChild(btn);
            });
            if ((body.users || []).length === 0) {
                listEl.innerHTML = '<p class="hint-text">利用者が登録されていません。介護スタッフに登録を依頼してください。</p>';
            }
        } catch (e) {
            listEl.innerHTML = '';
            errorEl.textContent = '利用者一覧の取得に失敗しました';
            errorEl.style.display = 'block';
        }
    }

    async function loginAsUser(userId) {
        const errorEl = document.getElementById('login-error');
        errorEl.style.display = 'none';
        const screenInput = document.getElementById('login-screen').value.trim() || '1';

        try {
            const res = await fetch('php/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: userId }),
            });
            const body = await res.json();
            if (!res.ok) {
                errorEl.textContent = body.error || 'ログインに失敗しました';
                errorEl.style.display = 'block';
                return;
            }
            token = body.token;
            user = body.user;
            screenId = screenInput;
            sessionStorage.setItem('braincare_token', token);
            sessionStorage.setItem('braincare_user', JSON.stringify(user));
            sessionStorage.setItem('braincare_screen', screenId);
            connectWs();
        } catch (e) {
            errorEl.textContent = '通信エラーが発生しました';
            errorEl.style.display = 'block';
        }
    }

    document.getElementById('btn-logout').addEventListener('click', () => {
        sessionStorage.clear();
        token = null;
        user = null;
        if (ws) ws.close();
        showScreen('login');
        loadNameList();
    });

    // ------- メニュー -------
    function showMenu() {
        document.getElementById('menu-user-name').textContent = user?.name || '';
        showScreen('menu');
        loadDailyGoals();
        loadAnnouncements();
    }

    async function loadAnnouncements() {
        const card = document.getElementById('menu-announcements-card');
        const list = document.getElementById('menu-announcements-list');
        try {
            const res = await fetch('php/messages.php', {
                headers: { Authorization: `Bearer ${token}` },
            });
            if (!res.ok) return;
            const data = await res.json();
            if (!data.messages || data.messages.length === 0) {
                card.style.display = 'none';
                return;
            }
            card.style.display = 'block';
            list.innerHTML = data.messages.map((m) => `
                <li>
                    <div class="hint-text">${m.created_at}（${escapeHtmlKonto(m.sender_name)}さんより）</div>
                    <div>${escapeHtmlKonto(m.body)}</div>
                </li>
            `).join('');
        } catch (e) {
            card.style.display = 'none';
        }
    }

    function escapeHtmlKonto(str) {
        return String(str).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    let latestSummary = null;

    async function fetchSummary() {
        const res = await fetch('php/me.php?action=summary', {
            headers: { Authorization: `Bearer ${token}` },
        });
        if (!res.ok) return null;
        latestSummary = await res.json();
        return latestSummary;
    }

    // ------- プレイカレンダー -------
    const calendarState = (() => {
        const now = new Date();
        return { year: now.getFullYear(), month: now.getMonth() };
    })();

    function renderCalendar() {
        const playDates = new Set((latestSummary && latestSummary.play_dates) || []);
        const { year, month } = calendarState;
        document.getElementById('mypage-cal-label').textContent = `${year}年${month + 1}月`;

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
        document.getElementById('mypage-calendar').innerHTML = html;
    }

    document.getElementById('mypage-cal-prev').addEventListener('click', () => {
        calendarState.month--;
        if (calendarState.month < 0) { calendarState.month = 11; calendarState.year--; }
        renderCalendar();
    });
    document.getElementById('mypage-cal-next').addEventListener('click', () => {
        calendarState.month++;
        if (calendarState.month > 11) { calendarState.month = 0; calendarState.year++; }
        renderCalendar();
    });

    async function loadDailyGoals() {
        const listEl = document.getElementById('menu-goal-list');
        const percentEl = document.getElementById('menu-goal-percent');
        const data = await fetchSummary();
        if (!data) return;

        percentEl.textContent = `達成率 ${data.daily_goals.percent}%`;
        listEl.innerHTML = data.daily_goals.goals.map((g) => `
            <li class="goal-item ${g.done ? 'done' : ''}">
                <span class="check">${g.done ? '☑' : '☐'}</span><span>${g.label}</span>
            </li>
        `).join('');
    }

    // ------- 自分の記録 -------
    document.getElementById('btn-open-mypage').addEventListener('click', () => {
        showScreen('mypage');
        loadMyPage();
    });
    document.getElementById('btn-mypage-back').addEventListener('click', showMenu);

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

    async function loadMyPage() {
        const tiles = document.getElementById('mypage-stat-tiles');
        const badgeList = document.getElementById('mypage-badge-list');
        const tbody = document.querySelector('#mypage-history-table tbody');
        tiles.innerHTML = '<p class="hint-text">読み込み中…</p>';
        badgeList.innerHTML = '';
        tbody.innerHTML = '';

        try {
            const data = await fetchSummary();
            if (!data) {
                tiles.innerHTML = '<p class="error-text">取得に失敗しました</p>';
                return;
            }

            const rankText = data.ranking.position
                ? `${data.ranking.position} / ${data.ranking.total_ranked} 位`
                : 'まだ対戦していません';

            badgeList.innerHTML = data.badges.map((b) => `
                <div class="badge-tile ${b.earned ? '' : 'locked'}">
                    <span class="emoji">${b.emoji}</span>
                    <div class="label">${b.label}</div>
                </div>
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
                document.getElementById('mypage-chart-game-type'),
                data.by_game_type
            );

            renderCalendar();

            tbody.innerHTML = data.history.map((h) => `
                <tr>
                    <td>${h.created_at}</td>
                    <td>${GAME_LABELS[h.game_type] || h.game_type}${sourceTag(h.source)}</td>
                    <td>${h.score}</td>
                    <td>${h.correct} / ${h.total_rounds}</td>
                </tr>
            `).join('') || '<tr><td colspan="4" class="hint-text">まだプレイ履歴がありません</td></tr>';
        } catch (e) {
            tiles.innerHTML = '<p class="error-text">通信エラーが発生しました</p>';
        }
    }

    function buildSoloList() {
        const soloList = document.getElementById('solo-game-list');
        soloList.innerHTML = '';

        GAME_TYPES.forEach((type) => {
            const soloBtn = document.createElement('button');
            soloBtn.className = 'btn';
            soloBtn.textContent = GAME_LABELS[type];
            soloBtn.addEventListener('click', () => {
                ws.send(JSON.stringify({ type: 'start_solo', game_type: type }));
                showScreen('game');
            });
            soloList.appendChild(soloBtn);
        });
    }
    buildSoloList();

    document.getElementById('btn-goto-solo').addEventListener('click', () => {
        showScreen('soloMenu');
    });
    document.getElementById('btn-solo-back').addEventListener('click', showMenu);

    document.getElementById('btn-start-test').addEventListener('click', () => {
        ws.send(JSON.stringify({ type: 'start_solo', game_type: 'cognitive_test' }));
        showScreen('game');
    });

    document.getElementById('btn-weak-mode').addEventListener('click', async () => {
        const data = await fetchSummary();
        const weakest = data && data.by_game_type.length > 0 ? data.by_game_type[0].game_type : null;
        if (!weakest) {
            alert('まだプレイ記録がありません。まずは「一人で遊ぶ」から試してみてください。');
            showScreen('soloMenu');
            return;
        }
        ws.send(JSON.stringify({ type: 'start_solo', game_type: weakest }));
        showScreen('game');
    });

    document.getElementById('btn-goto-join').addEventListener('click', () => {
        document.getElementById('join-error').style.display = 'none';
        document.getElementById('join-code-input').value = '';
        showScreen('join');
    });
    document.getElementById('btn-join-back').addEventListener('click', showMenu);

    document.getElementById('btn-do-join').addEventListener('click', () => {
        const code = document.getElementById('join-code-input').value.trim();
        const errorEl = document.getElementById('join-error');
        if (!/^\d{4}$/.test(code)) {
            errorEl.textContent = '4桁の参加コードを入力してください';
            errorEl.style.display = 'block';
            return;
        }
        errorEl.style.display = 'none';
        ws.send(JSON.stringify({ type: 'battle_room_join', code }));
    });

    document.getElementById('btn-cancel-waiting').addEventListener('click', () => {
        // 待機/対戦を中断してメニューへ戻る（サーバ側は切断検知でクリーンアップされる）
        ws.close();
        connectWs();
    });

    document.getElementById('btn-quit-game').addEventListener('click', () => {
        ws.close();
        connectWs();
    });

    document.getElementById('btn-back-to-menu').addEventListener('click', () => {
        showMenu();
    });

    // ------- ゲーム進行 -------
    function renderQuestion(data) {
        showScreen('game');
        document.getElementById('game-message').style.display = 'none';
        const roundLabelPrefix = data.mode === 'test' ? '認知機能テスト ' : '';
        document.getElementById('game-round-label').textContent = `${roundLabelPrefix}${data.round} / ${data.total_rounds} 問目`;
        document.getElementById('game-score-label').textContent = data.mode === 'battle' ? '対戦中' : (data.mode === 'test' ? 'テスト中' : '');

        const area = document.getElementById('game-input-area');
        area.innerHTML = '';
        answerBuffer = [];

        if (data.game_type === 'memory' && data.memorize_ms) {
            area.innerHTML = `<p class="main-question" style="font-size:1.6rem;">テレビをよく見て覚えてください</p>`;
            speak('テレビをよく見て覚えてください');
            setTimeout(() => {
                renderInputWidget(data, area);
                speak('覚えた順に入力してください');
            }, data.memorize_ms);
            return;
        }

        speakQuestionText(data);
        renderInputWidget(data, area);
    }

    function speakQuestionText(data) {
        const texts = {
            calc: `${data.expression}は、いくつでしょう`,
            true_false: `${data.text}。正しいか、正しくないか、お答えください`,
            number_order: '小さい数字から順にタップしてください',
            word_scramble: '文字を並べ替えて、言葉を完成させてください',
            spot_difference: '見本と違うところをタップしてください',
        };
        speak(texts[data.game_type] || '');
    }

    function renderInputWidget(data, area) {
        area.innerHTML = '';
        switch (data.input_type) {
            case 'numeric':
                renderNumericPad(data, area);
                break;
            case 'true_false':
                renderTrueFalse(data, area);
                break;
            case 'grid_tap':
                renderGridTap(data, area);
                break;
            case 'letter_tiles':
                renderLetterTiles(data, area);
                break;
            case 'color_tiles':
                renderColorTiles(data, area);
                break;
            case 'tap_point':
                renderTapPoint(data, area);
                break;
            default:
                area.textContent = '対応していない問題形式です';
        }
    }

    function sendAnswer(payload) {
        ws.send(JSON.stringify({ type: 'answer', payload }));
    }

    function renderNumericPad(data, area) {
        const isSequence = data.game_type === 'memory';
        const display = document.createElement('p');
        display.className = 'main-question';
        display.style.fontSize = '2rem';
        display.textContent = '';
        area.appendChild(display);

        const grid = document.createElement('div');
        grid.className = 'tile-grid';
        area.appendChild(grid);

        const buffer = [];
        const updateDisplay = () => { display.textContent = buffer.join(isSequence ? ' ' : ''); };

        [1, 2, 3, 4, 5, 6, 7, 8, 9].forEach((n) => {
            const btn = document.createElement('button');
            btn.className = 'btn';
            btn.textContent = n;
            btn.addEventListener('click', () => { buffer.push(n); updateDisplay(); });
            grid.appendChild(btn);
        });

        const delBtn = document.createElement('button');
        delBtn.className = 'btn btn-outline';
        delBtn.textContent = '←削除';
        delBtn.addEventListener('click', () => { buffer.pop(); updateDisplay(); });
        grid.appendChild(delBtn);

        const zeroBtn = document.createElement('button');
        zeroBtn.className = 'btn';
        zeroBtn.textContent = '0';
        zeroBtn.addEventListener('click', () => { buffer.push(0); updateDisplay(); });
        grid.appendChild(zeroBtn);

        const submitBtn = document.createElement('button');
        submitBtn.className = 'btn btn-success';
        submitBtn.textContent = '回答';
        submitBtn.addEventListener('click', () => {
            if (isSequence) {
                sendAnswer({ sequence: buffer.slice() });
            } else {
                sendAnswer({ value: parseInt(buffer.join('') || '0', 10) });
            }
        });
        grid.appendChild(submitBtn);
    }

    function renderTrueFalse(data, area) {
        const text = document.createElement('p');
        text.className = 'main-question';
        text.style.fontSize = '1.6rem';
        text.textContent = data.text;
        area.appendChild(text);

        const grid = document.createElement('div');
        grid.className = 'true-false-grid';
        area.appendChild(grid);

        const oBtn = document.createElement('button');
        oBtn.className = 'btn btn-success';
        oBtn.textContent = '○';
        oBtn.addEventListener('click', () => sendAnswer({ answer: true }));
        grid.appendChild(oBtn);

        const xBtn = document.createElement('button');
        xBtn.className = 'btn btn-danger';
        xBtn.textContent = '×';
        xBtn.addEventListener('click', () => sendAnswer({ answer: false }));
        grid.appendChild(xBtn);
    }

    function renderGridTap(data, area) {
        const hint = document.createElement('p');
        hint.className = 'hint-text';
        hint.textContent = '小さい数字から順にタップしてください';
        area.appendChild(hint);

        const grid = document.createElement('div');
        grid.className = 'tile-grid';
        area.appendChild(grid);

        const order = [];
        data.grid.forEach((num, pos) => {
            const btn = document.createElement('button');
            btn.className = 'btn';
            btn.textContent = num;
            btn.addEventListener('click', () => {
                if (btn.disabled) return;
                btn.disabled = true;
                btn.classList.add('is-pressed');
                order.push(pos);
                if (order.length === data.grid.length) {
                    sendAnswer({ order });
                }
            });
            grid.appendChild(btn);
        });
    }

    function renderLetterTiles(data, area) {
        const display = document.createElement('p');
        display.className = 'main-question';
        display.style.fontSize = '2rem';
        area.appendChild(display);

        const grid = document.createElement('div');
        grid.className = 'tile-grid';
        area.appendChild(grid);

        const answer = [];
        const updateDisplay = () => { display.textContent = answer.join(''); };

        data.tiles.forEach((ch) => {
            const btn = document.createElement('button');
            btn.className = 'btn';
            btn.textContent = ch;
            btn.addEventListener('click', () => {
                if (btn.disabled) return;
                btn.disabled = true;
                answer.push(ch);
                updateDisplay();
                if (answer.length === data.tiles.length) {
                    sendAnswer({ answer: answer.join('') });
                }
            });
            grid.appendChild(btn);
        });

        const clearBtn = document.createElement('button');
        clearBtn.className = 'btn btn-outline';
        clearBtn.textContent = 'やり直す';
        clearBtn.addEventListener('click', () => {
            answer.length = 0;
            updateDisplay();
            grid.querySelectorAll('button').forEach((b) => { if (b !== clearBtn) b.disabled = false; });
        });
        grid.appendChild(clearBtn);
    }

    function renderColorTiles(data, area) {
        const hint = document.createElement('p');
        hint.className = 'hint-text';
        hint.textContent = `覚えた順に色をタップしてください（${data.length}個）`;
        area.appendChild(hint);

        const grid = document.createElement('div');
        grid.className = 'tile-grid';
        area.appendChild(grid);

        const sequence = [];
        (data.palette || []).forEach((color) => {
            const btn = document.createElement('button');
            btn.className = 'btn';
            btn.style.background = colorHex(color.id);
            btn.textContent = color.label;
            btn.addEventListener('click', () => {
                sequence.push(color.id);
                if (sequence.length === data.length) {
                    sendAnswer({ sequence });
                }
            });
            grid.appendChild(btn);
        });

        const clearBtn = document.createElement('button');
        clearBtn.className = 'btn btn-outline';
        clearBtn.textContent = 'やり直す';
        clearBtn.addEventListener('click', () => { sequence.length = 0; });
        grid.appendChild(clearBtn);
    }

    function colorHex(id) {
        return {
            red: '#e05252', blue: '#4a7fd6', green: '#4caf50', yellow: '#e0b23c', purple: '#8e5bd6',
        }[id] || '#888';
    }

    function drawShapes(ctx, shapes) {
        shapes.forEach((s) => {
            ctx.fillStyle = s.color;
            if (s.type === 'circle') {
                ctx.beginPath();
                ctx.arc(s.x, s.y, s.size, 0, Math.PI * 2);
                ctx.fill();
            } else if (s.type === 'square') {
                ctx.fillRect(s.x - s.size, s.y - s.size, s.size * 2, s.size * 2);
            } else {
                ctx.beginPath();
                ctx.moveTo(s.x, s.y - s.size);
                ctx.lineTo(s.x - s.size, s.y + s.size);
                ctx.lineTo(s.x + s.size, s.y + s.size);
                ctx.closePath();
                ctx.fill();
            }
        });
    }

    function renderTapPoint(data, area) {
        const hint = document.createElement('p');
        hint.className = 'hint-text';
        hint.textContent = '見本と違うところを、下の絵からタップしてください';
        area.appendChild(hint);

        const wrap = document.createElement('div');
        wrap.className = 'spot-canvas-pair';
        area.appendChild(wrap);

        const canvasA = document.createElement('canvas');
        canvasA.className = 'spot-canvas';
        canvasA.width = data.canvas.w;
        canvasA.height = data.canvas.h;
        const canvasB = document.createElement('canvas');
        canvasB.className = 'spot-canvas';
        canvasB.width = data.canvas.w;
        canvasB.height = data.canvas.h;
        wrap.appendChild(canvasA);
        wrap.appendChild(canvasB);

        drawShapes(canvasA.getContext('2d'), data.image_a);
        drawShapes(canvasB.getContext('2d'), data.image_b);

        canvasB.addEventListener('click', (ev) => {
            const rect = canvasB.getBoundingClientRect();
            const x = (ev.clientX - rect.left) * (canvasB.width / rect.width);
            const y = (ev.clientY - rect.top) * (canvasB.height / rect.height);
            sendAnswer({ x, y });
        });
    }

    function renderResult(data) {
        const el = document.getElementById('game-message');
        const mine = data.correct;
        el.style.display = 'block';
        el.className = 'result-banner ' + (mine ? 'correct' : 'incorrect');
        el.textContent = mine ? '正解！' : '残念…';
        speak(mine ? '正解です' : '残念でした');
        if (data.mode === 'solo' || data.mode === 'test') {
            document.getElementById('game-score-label').textContent = `スコア: ${data.score}`;
        } else if (data.scores) {
            document.getElementById('game-score-label').textContent =
                data.scores.map((s) => `${s.name}: ${s.score}`).join(' / ');
        }
        document.getElementById('game-input-area').innerHTML = '<p class="hint-text">次の問題をお待ちください…</p>';
    }

    function renderGameOver(data) {
        showScreen('gameover');
        let summary;
        if (data.mode === 'solo') {
            summary = `スコア ${data.score} 点（正解 ${data.correct} / ${data.total_rounds} 問）`;
        } else if (data.mode === 'test') {
            summary = `認知機能テストが終わりました。スコア ${data.score} 点（正解 ${data.correct} / ${data.total_rounds} 問）`;
        } else {
            const scoreText = (data.scores || []).map((s) => `${s.name}: ${s.score}点`).join(' / ');
            summary = data.winner_name ? `勝者: ${data.winner_name}さん（${scoreText}）` : `引き分け（${scoreText}）`;
            if (data.reason === 'disconnected') {
                summary += '（相手の接続が切れました）';
            }
        }
        document.getElementById('gameover-summary').textContent = summary;
        document.getElementById('btn-view-test-result').style.display = data.mode === 'test' ? 'block' : 'none';
        speak(summary);
    }

    document.getElementById('btn-view-test-result').addEventListener('click', () => {
        showScreen('mypage');
        loadMyPage();
    });

    // ------- 初期化 -------
    if (token && user) {
        connectWs();
    } else {
        showScreen('login');
        loadNameList();
    }
})();
