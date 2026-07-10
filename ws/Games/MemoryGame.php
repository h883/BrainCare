<?php
declare(strict_types=1);

namespace BrainCare\Games;

/**
 * 記憶ゲーム（数字/色を覚えて回答）
 * TVに数列/色列を一定時間表示し、その後スマホから同じ順番で入力させる。
 * 正解シーケンスはmainペイロードにのみ含め、kontoには選択肢パレットのみ送る。
 */
class MemoryGame extends AbstractGame
{
    private const COLORS = [
        ['id' => 'red', 'label' => '赤'],
        ['id' => 'blue', 'label' => '青'],
        ['id' => 'green', 'label' => '緑'],
        ['id' => 'yellow', 'label' => '黄'],
        ['id' => 'purple', 'label' => '紫'],
    ];

    private array $sequence = [];
    private string $mode = 'digits';

    public function __construct()
    {
        parent::__construct(totalRounds: 5, timeLimitMs: 20000);
    }

    public function type(): string
    {
        return 'memory';
    }

    public function nextQuestion(): array
    {
        $this->round++;
        $this->mode = ($this->round % 2 === 0) ? 'colors' : 'digits';
        $length = min(6, 3 + intdiv($this->round - 1, 2));

        $this->sequence = [];
        if ($this->mode === 'digits') {
            for ($i = 0; $i < $length; $i++) {
                $this->sequence[] = random_int(0, 9);
            }
        } else {
            $ids = array_column(self::COLORS, 'id');
            for ($i = 0; $i < $length; $i++) {
                $this->sequence[] = $ids[array_rand($ids)];
            }
        }

        $memorizeMs = 1500 + $length * 900;

        $mainPayload = [
            'round' => $this->round,
            'total_rounds' => $this->totalRounds,
            'mode' => $this->mode,
            'sequence' => $this->sequence,
            'memorize_ms' => $memorizeMs,
            'input_type' => $this->mode === 'digits' ? 'numeric' : 'color_tiles',
        ];

        $kontoPayload = [
            'round' => $this->round,
            'total_rounds' => $this->totalRounds,
            'mode' => $this->mode,
            'length' => $length,
            'memorize_ms' => $memorizeMs,
            'input_type' => $this->mode === 'digits' ? 'numeric' : 'color_tiles',
            'palette' => $this->mode === 'colors' ? self::COLORS : null,
        ];

        return ['main' => $mainPayload, 'konto' => $kontoPayload];
    }

    public function checkAnswer(array $payload): array
    {
        $submitted = $payload['sequence'] ?? [];
        $correct = is_array($submitted)
            && count($submitted) === count($this->sequence)
            && array_map('strval', $submitted) === array_map('strval', $this->sequence);

        return [
            'correct' => $correct,
            'correctAnswer' => $this->sequence,
            'points' => $correct ? (10 + count($this->sequence)) : 0,
        ];
    }
}
