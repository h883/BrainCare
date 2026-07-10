<?php
declare(strict_types=1);

namespace BrainCare\Games;

/** 文字並べ替え（単語を完成させる） */
class WordScrambleGame extends AbstractGame
{
    private const WORDS = [
        'ひまわり', 'さくら', 'てがみ', 'りんご', 'みかん',
        'さかな', 'とけい', 'かさ', 'つくえ', 'でんわ',
        'ほし', 'つき', 'たいよう', 'かぞく', 'ゆびわ',
    ];

    private string $answer = '';

    public function __construct()
    {
        parent::__construct(totalRounds: 20, timeLimitMs: 25000);
    }

    public function type(): string
    {
        return 'word_scramble';
    }

    public function nextQuestion(): array
    {
        $this->round++;
        $word = self::WORDS[array_rand(self::WORDS)];
        $this->answer = $word;

        $chars = mb_str_split($word);
        do {
            $scrambled = $chars;
            shuffle($scrambled);
        } while (count($chars) > 1 && $scrambled === $chars);

        $payload = [
            'round' => $this->round,
            'total_rounds' => $this->totalRounds,
            'tiles' => $scrambled,
            'input_type' => 'letter_tiles',
        ];

        return ['main' => $payload, 'konto' => $payload];
    }

    public function checkAnswer(array $payload): array
    {
        $submitted = trim((string) ($payload['answer'] ?? ''));
        $correct = $submitted === $this->answer;

        return [
            'correct' => $correct,
            'correctAnswer' => $this->answer,
            'points' => self::randomPoints($correct),
        ];
    }
}
