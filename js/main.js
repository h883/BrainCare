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

    const WS_URL = document.querySelector('meta[name="braincare-ws-url"]')?.content || 'ws://localhost:8080';
    const screenId = new URLSearchParams(location.search).get('screen') || '1';
    document.getElementById('idle-screen-label').textContent = `画面番号: ${screenId}`;

    const idleView = document.getElementById('idle-view');
    const gameView = document.getElementById('game-view');
    const gameoverView = document.getElementById('gameover-view');

    let countdownTimer = null;
    let ws = null;

    function showView(view) {
        idleView.style.display = view === 'idle' ? 'block' : 'none';
        gameView.style.display = view === 'game' ? 'flex' : 'none';
        gameoverView.style.display = view === 'gameover' ? 'block' : 'none';
        if (view === 'idle') {
            showHostState('select');
        }
    }

    function showHostState(state) {
        // state: 'select'（ゲームを選ぶ）または 'waiting'（参加コード表示中）
        document.getElementById('host-select-view').style.display = state === 'select' ? 'block' : 'none';
        document.getElementById('host-waiting-view').style.display = state === 'waiting' ? 'block' : 'none';
    }

    function connectWs() {
        ws = new WebSocket(WS_URL);
        ws.addEventListener('open', () => {
            ws.send(JSON.stringify({ type: 'hello', role: 'main', screen_id: screenId }));
        });
        ws.addEventListener('message', (ev) => {
            let data;
            try { data = JSON.parse(ev.data); } catch (e) { return; }
            handleServerMessage(data);
        });
        ws.addEventListener('close', () => {
            setTimeout(connectWs, 2000); // 自動再接続
        });
    }

    function buildHostGameList() {
        const list = document.getElementById('host-game-list');
        list.innerHTML = '';
        Object.entries(GAME_LABELS).forEach(([type, label]) => {
            const btn = document.createElement('button');
            btn.className = 'btn';
            btn.textContent = label;
            btn.addEventListener('click', () => {
                const rounds = parseInt(document.getElementById('host-rounds-select').value, 10);
                ws.send(JSON.stringify({ type: 'host_create_room', game_type: type, rounds }));
            });
            list.appendChild(btn);
        });
    }
    buildHostGameList();

    function handleServerMessage(data) {
        switch (data.type) {
            case 'hello_ack':
                showView('idle');
                break;
            case 'room_created':
                document.getElementById('host-code-label').textContent = data.code;
                document.getElementById('host-game-label').textContent = `${GAME_LABELS[data.game_type] || ''}（${data.rounds}問）`;
                document.getElementById('host-joined-label').textContent = '';
                showHostState('waiting');
                break;
            case 'host_player_joined':
                document.getElementById('host-joined-label').textContent = `${data.name}さんが参加しました。もう一人をお待ちください…`;
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

    function startCountdown(ms) {
        clearInterval(countdownTimer);
        if (!ms) {
            document.getElementById('main-timer-label').textContent = '';
            return;
        }
        const end = Date.now() + ms;
        const label = document.getElementById('main-timer-label');
        countdownTimer = setInterval(() => {
            const remain = Math.max(0, Math.ceil((end - Date.now()) / 1000));
            label.textContent = `残り時間: ${remain}秒`;
            if (remain <= 0) clearInterval(countdownTimer);
        }, 250);
    }

    // main.htmlは「みんなで遊ぶ」対戦専用の表示画面（ソロプレイ・認知機能テストはスマホのみで完結する）。
    function renderQuestion(data) {
        showView('game');
        document.getElementById('main-result-banner').style.display = 'none';
        document.getElementById('main-round-label').textContent = `${data.round} / ${data.total_rounds} 問目`;
        document.getElementById('main-game-label').textContent = GAME_LABELS[data.game_type] || '';
        startCountdown(data.time_limit_ms);

        const battlePanel = document.getElementById('main-battle-panel');
        if (data.scores) {
            battlePanel.style.display = 'flex';
            renderBattlePanel(data.scores);
        } else {
            battlePanel.style.display = 'none';
        }

        const area = document.getElementById('main-question-area');
        area.innerHTML = '';

        if (data.game_type === 'calc') {
            area.innerHTML = `<p class="main-question">${escapeHtml(data.expression)} = ？</p>`;
        } else if (data.game_type === 'true_false') {
            area.innerHTML = `<p class="main-question" style="font-size:2.4rem;">${escapeHtml(data.text)}</p>`;
        } else if (data.game_type === 'number_order') {
            renderNumberOrderGrid(area, data.grid);
        } else if (data.game_type === 'word_scramble') {
            const tiles = data.tiles.map((c) => `<span class="btn" style="display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;margin:6px;font-size:2rem;">${escapeHtml(c)}</span>`).join('');
            area.innerHTML = `<div>${tiles}</div>`;
        } else if (data.game_type === 'memory') {
            renderMemory(area, data);
        } else if (data.game_type === 'spot_difference') {
            renderSpotDifference(area, data);
        }
    }

    function renderBattlePanel(scores) {
        const panel = document.getElementById('main-battle-panel');
        panel.innerHTML = scores.map((s) => `
            <div class="player">
                <div>${escapeHtml(s.name)}</div>
                <div class="score">${s.score}</div>
            </div>
        `).join('');
    }

    function renderNumberOrderGrid(area, grid) {
        const wrap = document.createElement('div');
        wrap.className = 'tile-grid';
        wrap.style.maxWidth = '520px';
        grid.forEach((num) => {
            const cell = document.createElement('div');
            cell.className = 'btn';
            cell.style.background = '#4a8fb0';
            cell.textContent = num;
            wrap.appendChild(cell);
        });
        area.appendChild(wrap);
    }

    function renderMemory(area, data) {
        const label = document.createElement('p');
        label.className = 'main-question';
        area.appendChild(label);

        if (data.mode === 'colors') {
            const colorHex = { red: '#e05252', blue: '#4a7fd6', green: '#4caf50', yellow: '#e0b23c', purple: '#8e5bd6' };
            label.innerHTML = data.sequence.map((c) => `<span style="display:inline-block;width:56px;height:56px;border-radius:12px;background:${colorHex[c] || '#888'};margin:6px;"></span>`).join('');
        } else {
            label.textContent = data.sequence.join('  ');
        }

        setTimeout(() => {
            area.innerHTML = '<p class="main-question">思い出して、スマホで入力してください</p>';
        }, data.memorize_ms);
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

    function renderSpotDifference(area, data) {
        const wrap = document.createElement('div');
        wrap.className = 'spot-canvas-pair';
        area.appendChild(wrap);

        [data.image_a, data.image_b].forEach((shapes) => {
            const canvas = document.createElement('canvas');
            canvas.className = 'spot-canvas';
            canvas.width = data.canvas.w;
            canvas.height = data.canvas.h;
            wrap.appendChild(canvas);
            drawShapes(canvas.getContext('2d'), shapes);
        });
    }

    function renderResult(data) {
        clearInterval(countdownTimer);
        document.getElementById('main-timer-label').textContent = '';
        const banner = document.getElementById('main-result-banner');
        banner.style.display = 'block';
        renderBattlePanel(data.scores);
        banner.className = 'result-banner';
        banner.textContent = '正解発表！';
    }

    function renderGameOver(data) {
        showView('gameover');
        const scoreText = (data.scores || []).map((s) => `${s.name}: ${s.score}点`).join(' 対 ');
        const text = data.winner_name ? `勝者: ${data.winner_name}さん！（${scoreText}）` : `引き分け（${scoreText}）`;
        document.getElementById('gameover-text').textContent = text;

        setTimeout(() => {
            showView('idle');
            loadRanking();
        }, 8000);
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    async function loadRanking() {
        try {
            const res = await fetch('php/ranking.php');
            const body = await res.json();
            const tbody = document.getElementById('idle-ranking-body');
            tbody.innerHTML = (body.ranking || []).map((r, i) => `
                <tr>
                    <td>${i + 1}</td>
                    <td>${escapeHtml(r.name)}</td>
                    <td>${r.point}</td>
                    <td>${r.win}勝 ${r.lose}敗</td>
                </tr>
            `).join('');
        } catch (e) {
            // 表示できなくても致命的ではないため無視する
        }
    }

    showView('idle');
    loadRanking();
    setInterval(loadRanking, 20000);
    connectWs();
})();
