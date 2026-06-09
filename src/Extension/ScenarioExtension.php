<?php

declare(strict_types=1);

namespace Lokris\ScenarioCoverage\Extension;

use Lokris\ScenarioCoverage\Coverage\ScenarioStore;
use Lokris\ScenarioCoverage\Extension\Subscriber\ExecutionFinishedSubscriber;
use Lokris\ScenarioCoverage\Extension\Subscriber\TestErroredSubscriber;
use Lokris\ScenarioCoverage\Extension\Subscriber\TestFailedSubscriber;
use Lokris\ScenarioCoverage\Extension\Subscriber\TestFinishedSubscriber;
use Lokris\ScenarioCoverage\Extension\Subscriber\TestPreparedSubscriber;
use Lokris\ScenarioCoverage\Extension\Subscriber\TestSkippedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Extension PHPUnit 10/11 — collecte le code coverage par scénario.
 *
 * Activation dans phpunit.xml :
 *
 *   <extensions>
 *     <bootstrap class="Lokris\ScenarioCoverage\Extension\ScenarioExtension">
 *       <parameter name="outputFile" value="var/scenario-coverage.json" />
 *       <parameter name="srcRoot"    value="src/" />
 *     </bootstrap>
 *   </extensions>
 *
 * Paramètres disponibles :
 *   - outputFile (défaut : var/scenario-coverage.json)
 *     Chemin du fichier JSON produit (relatif à la racine du projet).
 *   - srcRoot (défaut : src/)
 *     Seuls les fichiers sous ce chemin sont inclus dans les données de coverage.
 */
final class ScenarioExtension implements Extension
{
    public function bootstrap(
        Configuration     $configuration,
        Facade            $facade,
        ParameterCollection $parameters,
    ): void {
        // Résolution des paramètres (avec defaults)
        $projectRoot = getcwd() ?: '';
        $outputFile  = $projectRoot . '/' . ($parameters->has('outputFile')
            ? $parameters->get('outputFile')
            : 'var/scenario-coverage.json');
        $srcParam    = $parameters->has('srcRoot') ? $parameters->get('srcRoot') : 'src';
        // Accepter un srcRoot absolu comme relatif à la racine du projet.
        $srcPath     = str_starts_with($srcParam, '/') ? $srcParam : $projectRoot . '/' . $srcParam;
        $srcRoot     = realpath($srcPath);
        if ($srcRoot === false) {
            // Le dossier source n'existe pas (encore). On NE retombe PAS sur '' :
            // sinon le filtre srcRoot serait désactivé et TOUT le coverage (vendor/,
            // tests/, cache…) finirait dans le JSON, sans la moindre erreur visible.
            // On conserve le chemin voulu et on avertit.
            $srcRoot = $srcPath;
            fwrite(
                STDERR,
                "\n[scenario-coverage] ⚠️  Répertoire source introuvable : {$srcParam}\n" .
                "  → Le filtrage de couverture utilisera ce chemin tel quel.\n\n"
            );
        }

        $store = new ScenarioStore($outputFile, $srcRoot);

        // Avertir si Xdebug/pcov absent (rapport sans coverage mais metadata OK)
        if (!$store->isCoverageAvailable()) {
            fwrite(
                STDERR,
                "\n[scenario-coverage] ⚠️  Xdebug/pcov non disponible — " .
                "le rapport contiendra les métadonnées scénarios mais PAS le code coverage.\n" .
                "  → Installer ext-xdebug ou ext-pcov dans l'env de test.\n\n"
            );
        }

        // Enregistrement des subscribers.
        // Les subscribers Failed/Errored/Skipped renseignent le statut réel du test
        // (ils se déclenchent AVANT Finished) ; sans eux, tout test serait "passed".
        $facade->registerSubscribers(
            new TestPreparedSubscriber($store),
            new TestFailedSubscriber($store),
            new TestErroredSubscriber($store),
            new TestSkippedSubscriber($store),
            new TestFinishedSubscriber($store),
            new ExecutionFinishedSubscriber($store),
        );
    }
}
