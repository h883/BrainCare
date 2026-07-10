<?php
declare(strict_types=1);

namespace BrainCare\Games;

/**
 * 認知機能テスト（総合テスト）
 * 6つの分野（計算・記憶・数字順タッチ・文字並べ替え・○×・間違い探し）を1問ずつ出題し、
 * どの分野が苦手かをまとめて診断できるようにする。
 *
 * 各分野の結果は分野ごとにlearning_historyへ1件ずつ保存する（SessionManagerがdomainResults()を見て処理する）
 * ことで、既存の「苦手分野グラフ」（php/stats.php）にそのまま反映される。
 */
class CognitiveTestGame extends AbstractGame
{
    private const DOMAIN_ORDER = ['calc', 'memory', 'number_order', 'word_scramble', 'true_false', 'spot_difference'];

    /** @var GameInterface[] */
    private array $subEngines = [];

    /** @var array<int, array{game_type: string, score: int, correct: int}> */
    private array $domainResults = [];

    public function __construct()
    {
        parent::__construct(totalRounds: count(self::DOMAIN_ORDER), timeLimitMs: 15000);
        foreach (self::DOMAIN_ORDER as $domainType) {
            $engine = GameFactory::create($domainType);
            $engine->limitToSingleRound();
            $this->subEngines[] = $engine;
        }
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
        $sub = $this->subEngines[$this->round - 1];
        $q = $sub->nextQuestion();

        // 全体の進行(何問目/6問中)で上書きする（サブゲーム側は常に1問構成のため1/1になってしまう）
        $override = ['round' => $this->round, 'total_rounds' => $this->totalRounds];

        return [
            'main' => $override + $q['main'],
            'konto' => $override + $q['konto'],
        ];
    }

    public function checkAnswer(array $payload): array
    {
        $sub = $this->subEngines[$this->round - 1];
        $result = $sub->checkAnswer($payload);

        $this->domainResults[] = [
            'game_type' => $sub->type(),
            'score' => $result['points'],
            'correct' => $result['correct'] ? 1 : 0,
        ];

        return $result;
    }

    /** @return array<int, array{game_type: string, score: int, correct: int}> */
    public function domainResults(): array
    {
        return $this->domainResults;
    }

    private function currentSubEngine(): GameInterface
    {
        $index = max(0, min(count($this->subEngines) - 1, $this->round - 1));
        return $this->subEngines[$index];
    }
}
