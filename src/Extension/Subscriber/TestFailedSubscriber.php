<?php

declare(strict_types=1);

namespace Lokris\ScenarioCoverage\Extension\Subscriber;

use Lokris\ScenarioCoverage\Coverage\ScenarioStore;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;

/**
 * Déclenché quand une assertion du test échoue.
 * Marque le test courant comme "failed" avant l'event Finished.
 */
final class TestFailedSubscriber implements FailedSubscriber
{
    public function __construct(private readonly ScenarioStore $store)
    {
    }

    public function notify(Failed $event): void
    {
        $this->store->markStatus('failed');
    }
}
