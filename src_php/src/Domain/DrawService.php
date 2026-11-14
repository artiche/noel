<?php

declare(strict_types=1);

namespace Noel\Api\Domain;

use Noel\Api\Http\ApiException;

final class DrawService
{
    public function __construct(
        private readonly EdgeScoreCalculator $scoreCalculator,
        private readonly WeightedBipartiteMatcher $matcher
    ) {
    }

    /**
     * @param array{users: string[], forbiddenRecipientsByGiver: array<string, string[]>} $constraints
     * @param array{years: array<int, array<string,string>>} $history
     * @return array<string,string>|null giver => recipient
     */
    public function computeBestDraw(int $targetYear, array $constraints, array $history, ?int $seed): ?array
    {
        $this->validateConsistency($constraints, $history, $targetYear);

        $users = $constraints['users'];
        sort($users, SORT_STRING);
        $n = count($users);

        $weights = [];
        $allowed = [];
        for ($i = 0; $i < $n; $i++) {
            $giver = $users[$i];
            $weights[$i] = [];
            $allowed[$i] = [];
            for ($j = 0; $j < $n; $j++) {
                $recipient = $users[$j];
                $isAllowed = $giver !== $recipient
                    && !in_array($recipient, $constraints['forbiddenRecipientsByGiver'][$giver], true);
                $allowed[$i][$j] = $isAllowed;
                $weights[$i][$j] = $isAllowed
                    ? $this->scoreCalculator->computeScore($targetYear, $giver, $recipient, $history, $seed)
                    : PHP_INT_MIN / 4;
            }
        }

        $assignment = $this->matcher->solveMaximumPerfectMatching($weights, $allowed);
        if ($assignment === null) {
            return null;
        }

        $result = [];
        for ($i = 0; $i < $n; $i++) {
            $result[$users[$i]] = $users[$assignment[$i]];
        }

        return $result;
    }

    /**
     * @param array{users: string[], forbiddenRecipientsByGiver: array<string, string[]>} $constraints
     * @param array{years: array<int, array<string,string>>} $history
     * @return array<string, string[]>
     */
    public function getPossibleRecipients(int $targetYear, array $constraints, array $history, ?int $seed): array
    {
        $this->validateConsistency($constraints, $history, $targetYear);

        $result = [];
        foreach ($constraints['users'] as $giver) {
            $candidates = [];
            foreach ($constraints['users'] as $recipient) {
                $isAllowed = $giver !== $recipient
                    && !in_array($recipient, $constraints['forbiddenRecipientsByGiver'][$giver], true);
                if (!$isAllowed) {
                    continue;
                }

                $candidates[] = [
                    'recipient' => $recipient,
                    'score' => $this->scoreCalculator->computeScore($targetYear, $giver, $recipient, $history, $seed),
                ];
            }

            usort($candidates, static function (array $a, array $b): int {
                if ($a['score'] === $b['score']) {
                    return strcmp($a['recipient'], $b['recipient']);
                }
                return $b['score'] <=> $a['score'];
            });

            $result[$giver] = array_map(static fn (array $item) => $item['recipient'], $candidates);
        }

        return $result;
    }

    /**
     * @param array{users: string[], forbiddenRecipientsByGiver: array<string, string[]>} $constraints
     * @param array{years: array<int, array<string,string>>} $history
     */
    private function validateConsistency(array $constraints, array $history, int $targetYear): void
    {
        $users = $constraints['users'];
        $knownUsers = array_fill_keys($users, true);

        foreach ($history['years'] as $year => $assignments) {
            if ($year >= $targetYear) {
                continue;
            }
            if (count($assignments) !== count($users)) {
                throw new ApiException(422, 'invalid_history', "History {$year} has inconsistent participant count");
            }

            $seenGivers = [];
            foreach ($assignments as $recipient => $giver) {
                if (!isset($knownUsers[$recipient])) {
                    throw new ApiException(422, 'invalid_history', "Unknown recipient '{$recipient}' in history {$year}");
                }
                if (!isset($knownUsers[$giver])) {
                    throw new ApiException(422, 'invalid_history', "Unknown giver '{$giver}' in history {$year}");
                }
                if (isset($seenGivers[$giver])) {
                    throw new ApiException(422, 'invalid_history', "Giver '{$giver}' appears more than once in history {$year}");
                }
                $seenGivers[$giver] = true;
            }
        }
    }
}
