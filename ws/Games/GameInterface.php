<?php
declare(strict_types=1);

namespace BrainCare\Games;

/**
 * 各脳トレゲームの共通インターフェース。
 * ソロプレイ・対戦プレイの両方で同じ実装を使い回す（SessionManager/BattleManagerが進行を管理）。
 *
 * 正解はサーバ側（このクラスのインスタンス内）にのみ保持し、nextQuestion()の返り値には含めない。
 * 'main'（TV表示用）と'konto'（スマホ操作用）でペイロードを分けられるようにしているのは、
 * 記憶ゲームのようにTVにしか見せてはいけない情報（記憶フェーズの数列）があるため。
 */
interface GameInterface
{
    public function type(): string;

    public function totalRounds(): int;

    public function timeLimitMs(): int;

    /**
     * 次の問題を生成し、内部に正解を保持する。
     * @return array{main: array, konto: array}
     */
    public function nextQuestion(): array;

    /**
     * @return array{correct: bool, correctAnswer: mixed, points: int}
     */
    public function checkAnswer(array $payload): array;

    public function isFinished(): bool;

    public function currentRound(): int;

    /** 総合テスト（CognitiveTestGame）が各分野を1問ずつ出題するために使う */
    public function limitToSingleRound(): void;

    /** 対戦（みんなで遊ぶ）でTV側が選んだ出題数に変更するために使う */
    public function setTotalRounds(int $rounds): void;
}
