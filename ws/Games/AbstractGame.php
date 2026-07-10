<?php
declare(strict_types=1);

namespace BrainCare\Games;

abstract class AbstractGame implements GameInterface
{
    protected int $round = 0;
    protected int $totalRounds;
    protected int $timeLimitMs;

    public function __construct(int $totalRounds = 5, int $timeLimitMs = 15000)
    {
        $this->totalRounds = $totalRounds;
        $this->timeLimitMs = $timeLimitMs;
    }

    public function totalRounds(): int
    {
        return $this->totalRounds;
    }

    public function timeLimitMs(): int
    {
        return $this->timeLimitMs;
    }

    public function isFinished(): bool
    {
        return $this->round >= $this->totalRounds;
    }

    public function currentRound(): int
    {
        return $this->round;
    }

    public function limitToSingleRound(): void
    {
        $this->totalRounds = 1;
    }

    protected static function randomPoints(bool $correct): int
    {
        return $correct ? 10 : 0;
    }
}
