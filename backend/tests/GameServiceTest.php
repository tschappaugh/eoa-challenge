<?php

declare(strict_types=1);

namespace Tests;

use Tests\Support\DatabaseTestCase;

final class GameServiceTest extends DatabaseTestCase
{
    public function testAddCreatesTheGameUpstreamAndLocallyAndUsesTheDailyAction(): void
    {
        $user = $this->makeUser();

        $game = $this->services->games()->add($user, '  Zelda ');

        $this->assertSame('Zelda', $game['title']);
        $this->assertTrue($game['canRemove']);
        $this->assertCount(1, $this->eoa->games);
        $status = $this->services->limits()->status($user);
        $this->assertFalse($status['actionAvailable']);
        $this->assertSame('add', $status['action']['type']);
        $this->assertSame($game['id'], $status['action']['gameId']);
    }

    public function testDuplicateTitlesAreRejectedCaseAndWhitespaceInsensitively(): void
    {
        $this->services->games()->add($this->makeUser(), 'Super Mario');

        foreach (['super mario', 'SUPER MARIO', '  Super   Mario  '] as $variant) {
            $this->assertApiError(
                'DUPLICATE_TITLE',
                fn () => $this->services->games()->add($this->makeUser(), $variant)
            );
        }
        $this->assertCount(1, $this->eoa->games);
        $this->assertSame(1, $this->eoa->countCalls('addGame'));
    }

    public function testRejectedDuplicateDoesNotConsumeTheDailyAction(): void
    {
        $this->services->games()->add($this->makeUser(), 'Zelda');
        $user = $this->makeUser();

        $this->assertApiError('DUPLICATE_TITLE', fn () => $this->services->games()->add($user, 'zelda'));

        $this->assertTrue($this->services->limits()->status($user)['actionAvailable']);
        $this->services->games()->add($user, 'Metroid');
        $this->assertCount(2, $this->eoa->games);
    }

    public function testOnlyOneAddPerDay(): void
    {
        $user = $this->makeUser();
        $this->services->games()->add($user, 'Zelda');

        $this->assertApiError('DAILY_LIMIT_REACHED', fn () => $this->services->games()->add($user, 'Metroid'));

        $this->assertSame(1, $this->eoa->countCalls('addGame'));
        $this->assertCount(1, $this->eoa->games);
    }

    public function testActionIsAvailableAgainTheNextLocalDay(): void
    {
        $user = $this->makeUser();
        $this->services->games()->add($user, 'Zelda');

        $this->advance('+1 day');
        $this->services->games()->add($user, 'Metroid');

        $this->assertCount(2, $this->eoa->games);
    }

    public function testTheLimitResetsAtTheUsersLocalMidnightNotUtc(): void
    {
        $chicago = $this->makeUser('America/Chicago');
        $this->now = $this->now->setTime(5, 30); // 23:30 Nov 11 in Chicago
        $this->services->games()->add($chicago, 'Zelda');

        $this->now = $this->now->setTime(5, 59);
        $this->assertApiError('DAILY_LIMIT_REACHED', fn () => $this->services->games()->add($chicago, 'Metroid'));

        $this->now = $this->now->setTime(6, 0); // midnight in Chicago
        $this->services->games()->add($chicago, 'Metroid');
        $this->assertCount(2, $this->eoa->games);
    }

    public function testUpstreamFailureRefundsTheActionAndReleasesTheTitle(): void
    {
        $user = $this->makeUser();
        $this->eoa->failOn = ['addGame'];

        $this->assertApiError('UPSTREAM_UNAVAILABLE', fn () => $this->services->games()->add($user, 'Zelda'));

        $this->assertTrue($this->services->limits()->status($user)['actionAvailable']);
        $this->eoa->failOn = [];
        $this->services->games()->add($user, 'Zelda');
        $this->assertCount(1, $this->eoa->games);
    }

    public function testInvalidTitlesAreRejectedBeforeAnythingIsConsumed(): void
    {
        $user = $this->makeUser();

        foreach (['', '   ', null, str_repeat('x', 101)] as $bad) {
            $this->assertApiError('VALIDATION_FAILED', fn () => $this->services->games()->add($user, $bad));
        }

        $this->assertSame(0, $this->eoa->countCalls('addGame'));
        $this->assertTrue($this->services->limits()->status($user)['actionAvailable']);
    }

    public function testListSortsByVotesThenOldestFirst(): void
    {
        $user = $this->makeUser();
        $this->eoa->seed('Alpha', 1);
        $this->eoa->seed('Beta', 5);
        $this->eoa->seed('Gamma', 1);
        $this->eoa->seed('Delta', 0);

        $titles = array_column($this->services->games()->list($user), 'title');

        $this->assertSame(['Beta', 'Alpha', 'Gamma', 'Delta'], $titles);
    }

    public function testListImportsGamesAddedElsewhereAndDecodesTheirNames(): void
    {
        $this->eoa->seed('Tom & Jerry <3');

        $games = $this->services->games()->list($this->makeUser());

        $this->assertSame('Tom & Jerry <3', $games[0]['title']);
        $this->assertFalse($games[0]['canRemove']);
    }

    public function testImportedDuplicateTitlesAreAllListed(): void
    {
        $this->eoa->seed('Zelda');
        $this->eoa->seed('zelda');
        $this->eoa->seed('Zelda');

        $this->assertCount(3, $this->services->games()->list($this->makeUser()));
    }

    public function testListFlagsMyVotesAndMyGames(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $mine = $this->services->games()->add($me, 'Mine');
        $this->services->games()->add($other, 'Theirs');
        $this->advance('+1 day');
        $this->services->votes()->vote($me, $this->gameId('Theirs'));

        $games = array_column($this->services->games()->list($me), null, 'title');

        $this->assertTrue($games['Mine']['canRemove']);
        $this->assertFalse($games['Mine']['votedByMe']);
        $this->assertFalse($games['Theirs']['canRemove']);
        $this->assertTrue($games['Theirs']['votedByMe']);
        $this->assertSame(1, $games['Theirs']['votes']);
        $this->assertSame($mine['id'], $games['Mine']['id']);
    }

    public function testAnonymousVisitorsSeeTheListWithNothingMarkedAsTheirs(): void
    {
        $this->services->games()->add($this->makeUser(), 'Zelda');

        $games = $this->services->games()->list(null);

        $this->assertCount(1, $games);
        $this->assertFalse($games[0]['canRemove']);
        $this->assertFalse($games[0]['votedByMe']);
    }

    public function testListFailsCleanlyWhenUpstreamIsDown(): void
    {
        $this->eoa->down = true;

        $this->assertApiError('UPSTREAM_UNAVAILABLE', fn () => $this->services->games()->list($this->makeUser()));
    }

    public function testSyncPurgesLocalGamesRemovedUpstreamButKeepsFreshOnes(): void
    {
        $user = $this->makeUser();
        $old = $this->services->games()->add($user, 'Old');
        $fresh = $this->services->games()->add($this->makeUser(), 'Fresh');
        unset($this->eoa->games[1], $this->eoa->games[2]); // wiped upstream, e.g. /cache/flush
        $this->pdo->exec("UPDATE games SET created_at = NOW() - INTERVAL 5 MINUTE WHERE id = {$old['id']}");

        $this->services->games()->list($user);

        $this->assertSame(0, $this->gameId('Old'));
        $this->assertSame($fresh['id'], $this->gameId('Fresh'));
    }

    public function testOnlyTheCreatorCanRemoveAGame(): void
    {
        $owner = $this->makeUser();
        $game = $this->services->games()->add($owner, 'Zelda');

        $this->assertApiError('NOT_OWNER', fn () => $this->services->games()->remove($this->makeUser(), $game['id']));

        $this->assertCount(1, $this->eoa->games);
        $this->assertSame(0, $this->eoa->countCalls('removeGame'));
    }

    public function testImportedGamesCannotBeRemoved(): void
    {
        $this->eoa->seed('Elsewhere');
        $user = $this->makeUser();
        $this->services->games()->list($user);

        $id = $this->gameId('Elsewhere');

        $this->assertApiError('NOT_OWNER', fn () => $this->services->games()->remove($user, $id));
    }

    public function testOwnerRemovesTheGameEverywhereAndItsVotesGoWithIt(): void
    {
        $owner = $this->makeUser();
        $voter = $this->makeUser();
        $game = $this->services->games()->add($owner, 'Zelda');
        $this->services->votes()->vote($voter, $game['id']);

        $this->services->games()->remove($owner, $game['id']);

        $this->assertSame([], $this->eoa->games);
        $this->assertSame(0, $this->gameId('Zelda'));
        $this->assertSame(0, $this->rowCount('votes'));
    }

    public function testRemovingTodaysGameRefundsTheOwnersAddAndTodaysVoters(): void
    {
        $owner = $this->makeUser();
        $voter = $this->makeUser();
        $game = $this->services->games()->add($owner, 'Zelda');
        $this->services->votes()->vote($voter, $game['id']);

        $this->services->games()->remove($owner, $game['id']);

        $this->assertTrue($this->services->limits()->status($owner)['actionAvailable']);
        $this->assertTrue($this->services->limits()->status($voter)['actionAvailable']);
    }

    public function testRemovingAnOlderGameRefundsNothing(): void
    {
        $owner = $this->makeUser();
        $voter = $this->makeUser();
        $game = $this->services->games()->add($owner, 'Zelda');
        $this->services->votes()->vote($voter, $game['id']);
        $this->advance('+1 day');
        $this->services->games()->add($this->makeUser(), 'Other'); // unrelated

        $this->services->games()->remove($owner, $game['id']);

        // Yesterday's actions stay spent; they are history, not today's budget.
        $this->assertSame(1, $this->actionCount($owner));
        $this->assertSame(1, $this->actionCount($voter));
    }

    public function testRemoveLeavesEverythingIntactWhenUpstreamIsDown(): void
    {
        $owner = $this->makeUser();
        $game = $this->services->games()->add($owner, 'Zelda');
        $this->eoa->failOn = ['removeGame'];

        $this->assertApiError('UPSTREAM_UNAVAILABLE', fn () => $this->services->games()->remove($owner, $game['id']));

        $this->assertSame($game['id'], $this->gameId('Zelda'));
        $this->assertFalse($this->services->limits()->status($owner)['actionAvailable']);
    }

    public function testRemoveStillCleansUpWhenTheGameIsAlreadyGoneUpstream(): void
    {
        $owner = $this->makeUser();
        $game = $this->services->games()->add($owner, 'Zelda');
        $this->eoa->games = [];

        $this->services->games()->remove($owner, $game['id']);

        $this->assertSame(0, $this->gameId('Zelda'));
    }
}
