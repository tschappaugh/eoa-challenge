<?php

declare(strict_types=1);

namespace Tests;

use Tests\Support\DatabaseTestCase;

final class VoteServiceTest extends DatabaseTestCase
{
    private function game(string $title = 'Zelda'): int
    {
        $this->services->games()->add($this->makeUser(), $title);

        return $this->gameId($title);
    }

    public function testVoteCountsUpstreamAndUsesTheDailyAction(): void
    {
        $user = $this->makeUser();
        $id = $this->game();

        $this->services->votes()->vote($user, $id);

        $this->assertSame(1, $this->eoa->votes(1));
        $status = $this->services->limits()->status($user);
        $this->assertFalse($status['actionAvailable']);
        $this->assertSame('vote', $status['action']['type']);
    }

    public function testCannotVoteTwiceForTheSameGame(): void
    {
        $user = $this->makeUser();
        $id = $this->game();
        $this->services->votes()->vote($user, $id);
        $this->advance('+1 day');

        $this->assertApiError('ALREADY_VOTED', fn () => $this->services->votes()->vote($user, $id));

        $this->assertSame(1, $this->eoa->votes(1));
    }

    public function testOnlyOneVoteOrAddPerDay(): void
    {
        $user = $this->makeUser();
        $a = $this->game('A');
        $b = $this->game('B');
        $this->services->votes()->vote($user, $a);

        $this->assertApiError('DAILY_LIMIT_REACHED', fn () => $this->services->votes()->vote($user, $b));
        $this->assertApiError('DAILY_LIMIT_REACHED', fn () => $this->services->games()->add($user, 'C'));

        $this->assertSame(0, $this->eoa->votes(2));
    }

    public function testAddingAGameUsesTheDaysVote(): void
    {
        $user = $this->makeUser();
        $id = $this->game('Other');
        $this->services->games()->add($user, 'Mine');

        $this->assertApiError('DAILY_LIMIT_REACHED', fn () => $this->services->votes()->vote($user, $id));
    }

    public function testVotingForAnUnknownGameIsNotFound(): void
    {
        $this->assertApiError('NOT_FOUND', fn () => $this->services->votes()->vote($this->makeUser(), 999));
    }

    public function testUpstreamFailureRefundsTheActionAndRecordsNoVote(): void
    {
        $user = $this->makeUser();
        $id = $this->game();
        $this->eoa->failOn = ['vote'];

        $this->assertApiError('UPSTREAM_UNAVAILABLE', fn () => $this->services->votes()->vote($user, $id));

        $this->assertTrue($this->services->limits()->status($user)['actionAvailable']);
        $this->eoa->failOn = [];
        $this->services->votes()->vote($user, $id);
        $this->assertSame(1, $this->eoa->votes(1));
    }

    public function testVotingForAGameThatVanishedUpstreamCleansUpAndRefunds(): void
    {
        $user = $this->makeUser();
        $id = $this->game();
        $this->eoa->games = [];

        $this->assertApiError('NOT_FOUND', fn () => $this->services->votes()->vote($user, $id));

        $this->assertSame(0, $this->gameId('Zelda'));
        $this->assertTrue($this->services->limits()->status($user)['actionAvailable']);
    }

    public function testRemovingTodaysVoteHandsTheActionBack(): void
    {
        $user = $this->makeUser();
        $a = $this->game('A');
        $b = $this->game('B');
        $this->services->votes()->vote($user, $a);

        $this->services->votes()->removeVote($user, $a);

        $this->assertSame(0, $this->eoa->votes(1));
        $this->assertTrue($this->services->limits()->status($user)['actionAvailable']);
        $this->services->votes()->vote($user, $b);
        $this->assertSame(1, $this->eoa->votes(2));
    }

    public function testRemovingAnOlderVoteRefundsNothingAndNeedsNoAction(): void
    {
        $user = $this->makeUser();
        $a = $this->game('A');
        $b = $this->game('B');
        $c = $this->game('C');
        $this->services->votes()->vote($user, $a);
        $this->advance('+1 day');
        $this->services->votes()->vote($user, $b); // today's action is spent

        $this->services->votes()->removeVote($user, $a); // still allowed

        $this->assertApiError('DAILY_LIMIT_REACHED', fn () => $this->services->votes()->vote($user, $c));
        $this->assertSame(0, $this->eoa->votes(1));
    }

    public function testRemoveVoteWithoutAVoteIsNotFound(): void
    {
        $id = $this->game();

        $this->assertApiError('NOT_FOUND', fn () => $this->services->votes()->removeVote($this->makeUser(), $id));
    }

    public function testRemoveVoteRestoresEverythingWhenUpstreamFails(): void
    {
        $user = $this->makeUser();
        $id = $this->game();
        $this->services->votes()->vote($user, $id);
        $this->eoa->failOn = ['removeVote'];

        $this->assertApiError('UPSTREAM_UNAVAILABLE', fn () => $this->services->votes()->removeVote($user, $id));

        $games = $this->services->games()->list($user);
        $this->assertTrue($games[0]['votedByMe']);
        $this->assertFalse($this->services->limits()->status($user)['actionAvailable']);
    }

    public function testEachUserHasTheirOwnDailyAction(): void
    {
        $id = $this->game();

        $this->services->votes()->vote($this->makeUser(), $id);
        $this->services->votes()->vote($this->makeUser(), $id);

        $this->assertSame(2, $this->eoa->votes(1));
    }
}
