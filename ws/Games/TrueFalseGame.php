<?php
declare(strict_types=1);

namespace BrainCare\Games;

/** ○×クイズ（正しいと思う方を選択） */
class TrueFalseGame extends AbstractGame
{
    private const QUESTIONS = [
        ['text' => '1年は365日である', 'answer' => true],
        ['text' => '1週間は8日である', 'answer' => false],
        ['text' => '富士山は日本で一番高い山である', 'answer' => true],
        ['text' => '夏は寒くなる季節である', 'answer' => false],
        ['text' => '1時間は60分である', 'answer' => true],
        ['text' => 'リンゴは野菜である', 'answer' => false],
        ['text' => '太陽は西からのぼる', 'answer' => false],
        ['text' => '1メートルは100センチメートルである', 'answer' => true],
        ['text' => '魚は陸上で生活する動物である', 'answer' => false],
        ['text' => '雪は冬に降ることが多い', 'answer' => true],
        ['text' => '1ダースは10個である', 'answer' => false],
        ['text' => '心臓は血液を送り出す臓器である', 'answer' => true],
        ['text' => 'カラスは白い鳥である', 'answer' => false],
        ['text' => '1年は12ヶ月である', 'answer' => true],
        ['text' => '海の水はしょっぱい', 'answer' => true],
    ];

    private bool $answer = true;

    public function __construct()
    {
        parent::__construct(totalRounds: 20, timeLimitMs: 12000);
    }

    public function type(): string
    {
        return 'true_false';
    }

    public function nextQuestion(): array
    {
        $this->round++;
        $q = self::QUESTIONS[array_rand(self::QUESTIONS)];
        $this->answer = (bool) $q['answer'];

        $payload = [
            'round' => $this->round,
            'total_rounds' => $this->totalRounds,
            'text' => $q['text'],
            'input_type' => 'true_false',
        ];

        return ['main' => $payload, 'konto' => $payload];
    }

    public function checkAnswer(array $payload): array
    {
        $submitted = filter_var($payload['answer'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $correct = $submitted === $this->answer;

        return [
            'correct' => $correct,
            'correctAnswer' => $this->answer,
            'points' => self::randomPoints($correct),
        ];
    }
}
