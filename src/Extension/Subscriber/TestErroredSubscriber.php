<?php

declare(strict_types=1);

namespace Lokris\ScenarioCoverage\Extension\Subscriber;

use Lokris\ScenarioCoverage\Coverage\ScenarioStore;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;

/**
 * Déclenché quand le test lève une erreur/exception non attendue.
 * Marque le test courant comme "error" avant l'event Finished.
 */
final class TestErroredSubscriber implements ErroredSubscriber
{
    public function __construct(private readonly ScenarioStore $store)
    {
    }

    public function notify(Errored $event): void
    {
        $this->store->markStatus('error');
    }
}
