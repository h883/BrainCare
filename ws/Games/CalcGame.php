<?php
declare(strict_types=1);

namespace BrainCare\Games;

/** 計算問題（四則演算） */
class CalcGame extends AbstractGame
{
    private int $answer = 0;

    public function type(): string
    {
        return 'calc';
    }

    public function nextQuestion(): array
    {
        $this->round++;

        $ops = ['+', '-', '*', '/'];
        $op = $ops[array_rand($ops)];

        do {
            $a = random_int(1, 20);
            $b = random_int(1, 20);
            switch ($op) {
                case '+':
                    $answer = $a + $b;
                    break;
                case '-':
                    $answer = $a - $b;
                    break;
                case '*':
                    $a = random_int(1, 12);
                    $b = random_int(1, 12);
                    $answer = $a * $b;
                    break;
                case '/':
                    // 割り切れる組み合わせにする
                    $b = random_int(1, 9);
                    $answer = random_int(1, 12);
                    $a = $b * $answer;
                    break;
            }
        } while (false);

        $this->answer = $answer;
        $opLabel = ['+' => '＋', '-' => '－', '*' => '×', '/' => '÷'][$op];
        $expression = "{$a} {$opLabel} {$b}";

        $payload = [
            'round' => $this->round,
            'total_rounds' => $this->totalRounds,
            'expression' => $expression,
            'input_type' => 'numeric',
        ];

        return ['main' => $payload, 'konto' => $payload];
    }

    public function checkAnswer(array $payload): array
    {
        $value = (int) ($payload['value'] ?? null);
        $correct = $value === $this->answer;

        return [
            'correct' => $correct,
            'correctAnswer' => $this->answer,
            'points' => self::randomPoints($correct),
        ];
    }
}
