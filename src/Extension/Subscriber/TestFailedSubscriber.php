<?php

declare(strict_types=1);

namespace Lokris\TrajectoryCoverage\Extension\Subscriber;

use Lokris\TrajectoryCoverage\Coverage\TrajectoryStore;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;

/**
 * Déclenché quand une assertion du test échoue.
 * Marque le test courant comme "failed" avant l'event Finished.
 */
final class TestFailedSubscriber implements FailedSubscriber
{
    public function __construct(private readonly TrajectoryStore $store)
    {
    }

    public function notify(Failed $event): void
    {
        $this->store->markStatus('failed');
    }
}
