<?php

declare(strict_types=1);

namespace Lokris\TrajectoryCoverage\Extension\Subscriber;

use Lokris\TrajectoryCoverage\Coverage\TrajectoryStore;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;

/**
 * Déclenché quand le test lève une erreur/exception non attendue.
 * Marque le test courant comme "error" avant l'event Finished.
 */
final class TestErroredSubscriber implements ErroredSubscriber
{
    public function __construct(private readonly TrajectoryStore $store)
    {
    }

    public function notify(Errored $event): void
    {
        $this->store->markStatus('error');
    }
}
