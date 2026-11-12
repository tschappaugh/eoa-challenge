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

    /**
     * Visitors without an identity haven't acted yet, so their action is
     * available; their day is computed from the zone the browser reports.
     *
     * @return array<string, mixed>
     */
    public function status(?User $user, string $fallbackZone = 'UTC'): array
    {
        $zone = $user !== null ? $user->timezone : $fallbackZone;
        $today = $this->clock->today($zone);
        $action = $user === null ? null : $this->actions->find($user->id, $today);

        return [
            'timezone' => $zone,
            'localDate' => $today,
            'actionAvailable' => $action === null,
            'action' => $action === null ? null : ['type' => $action['type'], 'gameId' => $action['ref_id']],
            'resetsAt' => $this->clock->nextMidnight($zone)->format(DATE_ATOM),
        ];
    }
}
