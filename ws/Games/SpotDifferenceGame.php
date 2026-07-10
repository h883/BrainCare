<?php
declare(strict_types=1);

namespace BrainCare\Games;

/**
 * 間違い探し（2枚の画像を比較）
 * 実写真素材の代わりに、JS(Canvas)側で描画できる図形リストをサーバ側で生成する。
 * imageBはimageAのコピーから1つだけ図形を変化させたもの。
 */
class SpotDifferenceGame extends AbstractGame
{
    private const CANVAS_W = 400;
    private const CANVAS_H = 300;
    private const COLORS = ['#e05252', '#4a7fd6', '#4caf50', '#e0b23c', '#8e5bd6', '#e0752c'];
    private const SHAPES = ['circle', 'square', 'triangle'];
    private const TOLERANCE = 36;

    private array $center = ['x' => 0, 'y' => 0];

    public function __construct()
    {
        parent::__construct(totalRounds: 5, timeLimitMs: 25000);
    }

    public function type(): string
    {
        return 'spot_difference';
    }

    public function nextQuestion(): array
    {
        $this->round++;
        $shapeCount = min(9, 5 + $this->round);

        $imageA = [];
        for ($i = 0; $i < $shapeCount; $i++) {
            $imageA[] = [
                'id' => $i,
                'type' => self::SHAPES[array_rand(self::SHAPES)],
                'x' => random_int(30, self::CANVAS_W - 30),
                'y' => random_int(30, self::CANVAS_H - 30),
                'size' => random_int(18, 30),
                'color' => self::COLORS[array_rand(self::COLORS)],
            ];
        }

        $imageB = array_map(fn ($s) => $s, $imageA);
        $targetIndex = array_rand($imageB);
        $target = $imageB[$targetIndex];

        $variant = random_int(0, 2);
        if ($variant === 0) {
            // 色を変える
            $others = array_values(array_diff(self::COLORS, [$target['color']]));
            $target['color'] = $others[array_rand($others)];
        } elseif ($variant === 1) {
            // 位置をずらす（画面内に収まる範囲で）
            $target['x'] = min(self::CANVAS_W - 30, max(30, $target['x'] + (random_int(0, 1) ? 1 : -1) * random_int(40, 70)));
        } else {
            // 大きさを変える
            $target['size'] = $target['size'] >= 24 ? $target['size'] - 12 : $target['size'] + 12;
        }
        $imageB[$targetIndex] = $target;

        $this->center = ['x' => $target['x'], 'y' => $target['y']];

        $payload = [
            'round' => $this->round,
            'total_rounds' => $this->totalRounds,
            'canvas' => ['w' => self::CANVAS_W, 'h' => self::CANVAS_H],
            'image_a' => $imageA,
            'image_b' => array_values($imageB),
            'input_type' => 'tap_point',
        ];

        return ['main' => $payload, 'konto' => $payload];
    }

    public function checkAnswer(array $payload): array
    {
        $x = (float) ($payload['x'] ?? -1000);
        $y = (float) ($payload['y'] ?? -1000);
        $distance = sqrt((($x - $this->center['x']) ** 2) + (($y - $this->center['y']) ** 2));
        $correct = $distance <= self::TOLERANCE;

        return [
            'correct' => $correct,
            'correctAnswer' => $this->center,
            'points' => self::randomPoints($correct),
        ];
    }
}
