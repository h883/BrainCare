<?php
declare(strict_types=1);

namespace BrainCare\Games;

/** 数字順タッチ（指定された順番で数字を選択） */
class NumberOrderGame extends AbstractGame
{
    private array $numbers = [];
    private array $correctOrder = [];

    public function __construct()
    {
        parent::__construct(totalRounds: 5, timeLimitMs: 20000);
    }

    public function type(): string
    {
        return 'number_order';
    }

    public function nextQuestion(): array
    {
        $this->round++;
        $count = min(12, 6 + $this->round);

        $this->numbers = range(1, $count);
        shuffle($this->numbers);

        // 位置(index)を数値の昇順に並べたものが正解のタップ順
        $indexed = [];
        foreach ($this->numbers as $pos => $num) {
            $indexed[] = ['pos' => $pos, 'num' => $num];
        }
        usort($indexed, fn ($a, $b) => $a['num'] <=> $b['num']);
        $this->correctOrder = array_column($indexed, 'pos');

        $payload = [
            'round' => $this->round,
            'total_rounds' => $this->totalRounds,
            'grid' => $this->numbers,
            'input_type' => 'grid_tap',
        ];

        return ['main' => $payload, 'konto' => $payload];
    }

    public function checkAnswer(array $payload): array
    {
        $submitted = $payload['order'] ?? [];
        $correct = is_array($submitted)
            && array_map('intval', $submitted) === $this->correctOrder;

        return [
            'correct' => $correct,
            'correctAnswer' => $this->correctOrder,
            'points' => $correct ? (10 + count($this->numbers)) : 0,
        ];
    }
}
