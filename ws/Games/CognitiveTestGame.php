<?php
declare(strict_types=1);

namespace BrainCare\Games;

/**
 * 認知機能テスト（総合テスト）
 * 6つの分野（計算・記憶・数字順タッチ・文字並べ替え・○×・間違い探し）を各5問、
 * 出題順をシャッフルして合計30問出題し、どの分野が苦手かをまとめて診断できるようにする。
 *
 * 各分野の結果は分野ごとに集計してlearning_historyへ1件ずつ保存する
 * （SessionManagerがdomainResults()を見て処理する）ことで、
 * 既存の「苦手分野グラフ」（php/stats.php）にそのまま反映される。
 */
class CognitiveTestGame extends AbstractGame
{
    private const DOMAIN_ORDER = ['calc', 'memory', 'number_order', 'word_scramble', 'true_false', 'spot_difference'];
    private const QUESTIONS_PER_DOMAIN = 5;

    /** @var GameInterface[] */
    private array $subEngines = [];

    /** @var int[] 出題順（subEnginesのインデックスをシャッフルしたもの） */
    private array $schedule = [];

    private int $currentEngineIndex = 0;

    /** @var int[] 分野（subEnginesのインデックス）ごとの獲得スコア合計 */
    private array $domainScore = [];

    /** @var int[] 分野（subEnginesのインデックス）ごとの正解数 */
    private array $domainCorrect = [];

    public function __construct()
    {
        parent::__construct(totalRounds: count(self::DOMAIN_ORDER) * self::QUESTIONS_PER_DOMAIN, timeLimitMs: 15000);

        foreach (self::DOMAIN_ORDER as $index => $domainType) {
            $engine = GameFactory::create($domainType);
            $engine->setTotalRounds(self::QUESTIONS_PER_DOMAIN);
            $this->subEngines[] = $engine;
            $this->domainScore[$index] = 0;
            $this->domainCorrect[$index] = 0;

            for ($i = 0; $i < self::QUESTIONS_PER_DOMAIN; $i++) {
                $this->schedule[] = $index;
            }
        }
        shuffle($this->schedule);
    }

    public function type(): string
    {
        return $this->currentSubEngine()->type();
    }

    public function timeLimitMs(): int
    {
        return $this->currentSubEngine()->timeLimitMs();
    }

    public function nextQuestion(): array
    {
        $this->round++;
        $this->currentEngineIndex = $this->schedule[$this->round - 1];
        $sub = $this->subEngines[$this->currentEngineIndex];
        $q = $sub->nextQuestion();

        // 全体の進行(何問目/30問中)で上書きする（サブゲーム側は分野内の問数になってしまうため）
        $override = ['round' => $this->round, 'total_rounds' => $this->totalRounds];

        return [
            'main' => $override + $q['main'],
            'konto' => $override + $q['konto'],
        ];
    }

    public function checkAnswer(array $payload): array
    {
        $sub = $this->subEngines[$this->currentEngineIndex];
        $result = $sub->checkAnswer($payload);

        $this->domainScore[$this->currentEngineIndex] += $result['points'];
        if ($result['correct']) {
            $this->domainCorrect[$this->currentEngineIndex]++;
        }

        return $result;
    }

    /** @return array<int, array{game_type: string, score: int, correct: int, total_rounds: int}> */
    public function domainResults(): array
    {
        $results = [];
        foreach ($this->subEngines as $index => $sub) {
            $results[] = [
                'game_type' => $sub->type(),
                'score' => $this->domainScore[$index],
                'correct' => $this->domainCorrect[$index],
                'total_rounds' => $sub->totalRounds(),
            ];
        }
        return $results;
    }

    private function currentSubEngine(): GameInterface
    {
        return $this->subEngines[$this->currentEngineIndex];
    }
}
