<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Clock;
use App\Domain\User;
use App\Repository\DailyActionRepository;

final class DailyLimitService
{
    public function __construct(
        private readonly DailyActionRepository $actions,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(User $user): array
    {
        $today = $this->clock->today($user);
        $action = $this->actions->find($user->id, $today);

        return [
            'timezone' => $user->timezone,
            'localDate' => $today,
            'actionAvailable' => $action === null,
            'action' => $action === null ? null : ['type' => $action['type'], 'gameId' => $action['ref_id']],
            'resetsAt' => $this->clock->nextMidnight($user)->format(DATE_ATOM),
        ];
    }
}
