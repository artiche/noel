<?php

declare(strict_types=1);

namespace Noel\Api\Domain;

final class EdgeScoreCalculator
{
    private const NEVER_SEEN_BONUS = 1_000_000_000;
    private const AGE_MULTIPLIER = 1_000_000;
    private const TIE_BREAK_MAX = 999;

    /** @param array{years: array<int, array<string,string>>} $history */
    public function computeScore(int $targetYear, string $giver, string $recipient, array $history, ?int $seed): int
    {
        $seenYears = [];
        foreach ($history['years'] as $year => $recipientToGiver) {
            if ($year >= $targetYear) {
                continue;
            }

            $assignedGiver = $recipientToGiver[$recipient] ?? null;
            if ($assignedGiver === $giver) {
                $seenYears[] = $year;
            }
        }

        $neverSeen = count($seenYears) === 0;
        $age = $neverSeen ? $targetYear : ($targetYear - max($seenYears));
        $tieBreak = $this->buildDeterministicTieBreak($seed ?? 0, $giver, $recipient);

        return ($neverSeen ? self::NEVER_SEEN_BONUS : 0)
            + ($age * self::AGE_MULTIPLIER)
            + $tieBreak;
    }

    private function buildDeterministicTieBreak(int $seed, string $giver, string $recipient): int
    {
        $hash = crc32($seed . '|' . $giver . '|' . $recipient);
        return (int) ($hash % (self::TIE_BREAK_MAX + 1));
    }
}
