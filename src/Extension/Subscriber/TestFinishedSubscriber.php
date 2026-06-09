<?php

declare(strict_types=1);

namespace Lokris\ScenarioCoverage\Extension\Subscriber;

use Lokris\ScenarioCoverage\Coverage\ScenarioStore;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use ReflectionClass;
use Throwable;

/**
 * Déclenché après chaque méthode de test.
 *
 * Lit l'attribut #[Scenario] sur la classe de test via la réflexion,
 * puis délègue à ScenarioStore l'arrêt de capture et l'enregistrement.
 */
final class TestFinishedSubscriber implements FinishedSubscriber
{
    public function __construct(private readonly ScenarioStore $store)
    {
    }

    public function notify(Finished $event): void
    {
        $test = $event->test();

        // On ne traite que les TestMethod (pas les DataProvider/etc.)
        if (!$test instanceof TestMethod) {
            $this->store->recordNonScenario();
            return;
        }

        $className = $test->className();
        $testFile  = $test->file();
        // durationSincePrevious() retourne la durée depuis l'event précédent (≈ durée du test)
        $duration  = $event->telemetryInfo()->durationSincePrevious()->asFloat();

        // Statut réel : résolu par le store, alimenté par les subscribers
        // Failed/Errored/Skipped (qui se déclenchent avant cet event Finished).
        // 'passed' par défaut si aucun échec/skip n'a été signalé pour ce test.
        $status = $this->store->currentStatus();

        // Lire l'attribut #[Scenario] PUIS l'instancier — le tout dans le try.
        // La détection se fait par NOM COURT de classe ('Scenario'), donc indépendante
        // du namespace : tout attribut nommé Scenario (quel que soit son namespace) est
        // reconnu — typiquement Lokris\ScenarioCoverage\Attribute\Scenario.
        // Si la réflexion OU l'instanciation de l'attribut échoue (Error/TypeError du
        // constructeur), on arrête proprement la capture via recordNonScenario() pour
        // ne pas laisser une session Xdebug ouverte polluer le test suivant.
        try {
            $ref = new ReflectionClass($className);

            $scenarioAttr = null;
            foreach ($ref->getAttributes() as $attr) {
                $attrName = $attr->getName();
                if ($attrName === 'Scenario' || str_ends_with($attrName, '\\Scenario')) {
                    $scenarioAttr = $attr;
                    break;
                }
            }

            if ($scenarioAttr === null) {
                // Test non annoté comme scénario — on arrête la capture proprement.
                $this->store->recordNonScenario();
                return;
            }

            // Instancier l'attribut (tolérant sur l'absence de `tags`).
            $scenario = $scenarioAttr->newInstance();
        } catch (Throwable) {
            $this->store->recordNonScenario();
            return;
        }

        $this->store->endScenario(
            testClass: $className,
            testFile:  $testFile,
            status:    $status,
            duration:  $duration,
            title:     $scenario->title,
            desc:      $scenario->description,
            mandatory: $scenario->mandatory,
            tags:      property_exists($scenario, 'tags') ? $scenario->tags : [],
        );
    }
}
