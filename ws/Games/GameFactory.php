<?php
declare(strict_types=1);

namespace BrainCare\Games;

class GameFactory
{
    private const MAP = [
        'calc' => CalcGame::class,
        'memory' => MemoryGame::class,
        'number_order' => NumberOrderGame::class,
        'word_scramble' => WordScrambleGame::class,
        'true_false' => TrueFalseGame::class,
        'spot_difference' => SpotDifferenceGame::class,
        'cognitive_test' => CognitiveTestGame::class,
    ];

    public static function types(): array
    {
        return array_keys(self::MAP);
    }

    public static function create(string $gameType): GameInterface
    {
        if (!isset(self::MAP[$gameType])) {
            throw new \InvalidArgumentException("不明なgame_typeです: {$gameType}");
        }
        $class = self::MAP[$gameType];
        return new $class();
    }
}
