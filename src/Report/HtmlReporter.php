<?php

declare(strict_types=1);

namespace Lokris\ScenarioCoverage\Report;

use Lokris\ScenarioCoverage\Coverage\ScenarioRecord;

/**
 * Génère un rapport HTML interactif autonome (zéro dépendance externe).
 *
 * Le rapport est un unique fichier .html :
 *  - Arborescence de fichiers avec taux de couverture par scénario
 *  - Vue source avec highlighting PHP natif + annotations par scénario
 *  - Vue scénarios avec couverture inversée (scénario → fichiers)
 *  - Dashboard global
 */
final class HtmlReporter
{
    /**
     * @param ScenarioRecord[] $records   Enregistrements produits par ScenarioStore
     * @param string             $projectName  Nom affiché dans l'en-tête
     * @param string             $srcRoot   Chemin absolu du src/ (pour les paths relatifs)
     * @param string             $generatedAt Date de génération
     */
    public static function generate(
        array  $records,
        string $projectName  = 'Project',
        string $srcRoot      = '',
        string $generatedAt  = '',
    ): string {
        // ── 1-3. Agrégation déléguée à CoverageData (logique partagée) ────────
        // Même cœur de calcul que celui exposé aux consommateurs externes
        // (ex. dashboard Symfony) : aucune duplication de l'agrégation.
        $data          = CoverageData::fromRecords($records, $srcRoot, $generatedAt);
        $fileMap       = $data->fileMap();
        $scenarioStats = $data->scenarioStats();
        $fileStats     = $data->fileStats();
        $global        = $data->globalStats();

        // ── 4. Pré-rendu des sources PHP (highlight natif) ───────────────────
        /** @var array<string, string[]> $sourcesHtml  filePath => [line1_html, line2_html, ...] */
        $sourcesHtml = [];
        foreach (array_keys($fileMap) as $file) {
            if (!file_exists($file)) {
                $sourcesHtml[$file] = ['<span style="color:#888">// fichier non trouvé</span>'];
                continue;
            }
            $highlighted = highlight_string((string) file_get_contents($file), true);

            // PHP 8.x : sort de <pre><code ...>...\n...</code></pre> avec de vrais newlines
            // Anciennes versions : <code><span>...<br />...</span></code>
            // On détecte et normalise les deux formats.
            if (str_contains($highlighted, '<br />')) {
                // Ancien format (PHP < 8)
                $inner = preg_replace('#^<code><span[^>]*>(.*)</span></code>$#s', '$1', $highlighted) ?? $highlighted;
                $rawLines = explode('<br />', str_replace(["\r\n", "\n", "\r"], '', $inner));
            } else {
                // Nouveau format PHP 8 : <pre><code ...>...\n...</code></pre>
                // Retirer le wrapper <pre><code ...> / </code></pre>
                $inner = preg_replace('#^<pre><code[^>]*>(.*)</code></pre>$#s', '$1', $highlighted) ?? $highlighted;
                // Garder les spans ouverts cohérents par ligne : split sur les vraies newlines
                $rawLines = explode("\n", $inner);
                // Retirer la dernière ligne vide si présente
                if (end($rawLines) === '') {
                    array_pop($rawLines);
                }
            }

            $sourcesHtml[$file] = self::fixSpansPerLine($rawLines);
        }

        // ── 5. Construire l'arborescence des fichiers ─────────────────────────
        $tree = self::buildFileTree($fileStats, $srcRoot);

        // ── 6. Sérialiser en JSON pour le JS ──────────────────────────────────
        $dataJson = json_encode([
            'project'     => $projectName,
            'generatedAt' => $generatedAt,
            'srcRoot'     => $srcRoot,
            'stats'       => [
                'files'         => $global['files'],
                'scenarios'     => $global['scenarios'],
                'passed'        => $global['passed'],
                'globalPercent' => $global['globalPercent'],
                'globalCovered' => $global['globalCovered'],
                'globalTotal'   => $global['globalTotal'],
            ],
            'scenarios' => array_values($scenarioStats),
            'files'        => $fileStats,
            'fileLines'    => $fileMap,       // [file][line][] = tid
            'sources'      => $sourcesHtml,   // pre-highlighted HTML per file
            'tree'         => $tree,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return self::renderHtml($dataJson ?: '{}', $projectName, $generatedAt);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Corrige les spans qui traversent les sauts de ligne dans la sortie de highlight_string().
     *
     * highlight_string() peut ouvrir un <span style="..."> à la fin d'une ligne et le fermer
     * plusieurs lignes plus bas. On ferme chaque span ouvert à la fin de la ligne courante,
     * et on le rouvre au début de la suivante — garantissant un HTML valide par ligne.
     *
     * @param string[] $lines
     * @return string[]
     */
    private static function fixSpansPerLine(array $lines): array
    {
        $openSpans = [];  // pile des attributs style des spans ouverts
        $result    = [];

        foreach ($lines as $line) {
            // Rouvrir les spans hérités de la ligne précédente
            $prefix = '';
            foreach ($openSpans as $style) {
                $prefix .= '<span style="' . $style . '">';
            }

            // Analyser TOUS les tags span ouverts/fermés dans cette ligne (dans l'ordre)
            preg_match_all('#<span\s+style="([^"]*)">|</span>#', $line, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                if (isset($m[1]) && $m[1] !== '') {
                    // <span style="..."> — empiler
                    $openSpans[] = $m[1];
                } else {
                    // </span> — dépiler
                    array_pop($openSpans);
                }
            }

            // Fermer les spans encore ouverts à la fin de cette ligne
            $suffix = str_repeat('</span>', count($openSpans));

            $result[] = $prefix . $line . $suffix;
        }

        return $result;
    }

    /**
     * Construit l'arbre de fichiers depuis la carte de stats.
     *
     * @param array<string, array{covered: int, total: int, percent: int, scenarios: string[]}> $fileStats
     * @return array<string, mixed>
     */
    private static function buildFileTree(array $fileStats, string $srcRoot): array
    {
        $tree = [];

        foreach ($fileStats as $filePath => $stats) {
            // Chemin relatif pour l'affichage
            $rel = $srcRoot !== '' && str_starts_with($filePath, $srcRoot . '/')
                ? substr($filePath, strlen($srcRoot) + 1)
                : $filePath;

            $parts   = explode('/', ltrim($rel, '/'));
            $current = &$tree;

            foreach ($parts as $i => $part) {
                $isFile = ($i === count($parts) - 1);

                if (!isset($current[$part])) {
                    $current[$part] = $isFile
                        ? ['__file' => $filePath, '__stats' => $stats]
                        : [];
                }

                if (!$isFile) {
                    $current = &$current[$part];
                }
            }
        }

        return $tree;
    }

    /**
     * Produit le HTML final en injectant les données et les assets inline.
     */
    private static function renderHtml(string $dataJson, string $projectName, string $generatedAt = ''): string
    {
        // Nowdoc (<<<'HTML') = zéro interpolation PHP → les ${...} JavaScript passent intacts.
        // On injecte les deux seules valeurs PHP via str_replace après.
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Scenario Coverage — __PROJECT_NAME__</title>
<style>
/* ── Reset & Variables ────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0d1117; --bg2:#161b22; --bg3:#1c2333; --bg4:#21262d;
  --border:#30363d; --text:#e6edf3; --text2:#8b949e; --text3:#656d76;
  --green:#39d353; --green-dim:rgba(57,211,83,.15); --green-border:rgba(57,211,83,.4);
  --red:#f85149;   --red-dim:rgba(248,81,73,.12);   --red-border:rgba(248,81,73,.35);
  --yellow:#d29922; --yellow-dim:rgba(210,153,34,.15);
  --blue:#58a6ff;  --purple:#bc8cff; --orange:#ffa657;
  --radius:6px; --radius-lg:12px;
  --font-mono:'JetBrains Mono','Fira Code','Cascadia Code',monospace;
  --font-ui:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;
}

html,body{height:100%;overflow:hidden}
body{font-family:var(--font-ui);background:var(--bg);color:var(--text);font-size:13px;display:flex;flex-direction:column}

/* ── Header ──────────────────────────────────────────────────────────────── */
#header{
  display:flex;align-items:center;gap:16px;padding:12px 20px;
  background:var(--bg2);border-bottom:1px solid var(--border);
  flex-shrink:0;z-index:10;
}
#header h1{font-size:15px;font-weight:600;color:var(--text);display:flex;align-items:center;gap:8px}
#header h1 .icon{font-size:18px}
.stat-pill{
  display:flex;align-items:center;gap:5px;padding:3px 10px;
  border-radius:20px;border:1px solid var(--border);
  font-size:12px;font-weight:500;color:var(--text2);background:var(--bg3);
}
.stat-pill .val{color:var(--text);font-weight:700}
.coverage-ring{
  width:44px;height:44px;border-radius:50%;
  background:conic-gradient(var(--green) calc(var(--pct) * 1%),var(--bg4) 0);
  display:flex;align-items:center;justify-content:center;position:relative;
}
.coverage-ring::before{
  content:'';position:absolute;width:34px;height:34px;border-radius:50%;background:var(--bg2);
}
.coverage-ring span{position:relative;z-index:1;font-size:11px;font-weight:700;color:var(--text)}
.header-spacer{flex:1}
.gen-date{font-size:11px;color:var(--text3)}

/* ── App layout ──────────────────────────────────────────────────────────── */
#app{display:flex;flex:1;overflow:hidden}

/* ── Sidebar ─────────────────────────────────────────────────────────────── */
#sidebar{
  width:280px;min-width:200px;max-width:400px;
  background:var(--bg2);border-right:1px solid var(--border);
  display:flex;flex-direction:column;overflow:hidden;flex-shrink:0;
  resize:horizontal;
}
.sidebar-tabs{
  display:flex;border-bottom:1px solid var(--border);flex-shrink:0;
}
.sidebar-tab{
  flex:1;padding:10px 4px;text-align:center;cursor:pointer;
  font-size:11px;font-weight:600;color:var(--text2);
  border-bottom:2px solid transparent;transition:all .15s;letter-spacing:.3px;
  text-transform:uppercase;
}
.sidebar-tab:hover{color:var(--text);background:var(--bg3)}
.sidebar-tab.active{color:var(--blue);border-bottom-color:var(--blue)}
.sidebar-panel{flex:1;overflow-y:auto;padding:8px 0;display:none}
.sidebar-panel.active{display:block}

/* ── File tree ───────────────────────────────────────────────────────────── */
.tree-item{
  display:flex;align-items:center;gap:6px;padding:4px 12px;cursor:pointer;
  user-select:none;transition:background .1s;
}
.tree-item:hover{background:var(--bg3)}
.tree-item.active{background:var(--bg4)!important}
.tree-item .indent{flex-shrink:0}
.tree-item .icon{font-size:13px;flex-shrink:0;width:16px;text-align:center}
.tree-item .name{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px}
.tree-item .badge{
  flex-shrink:0;font-size:10px;font-weight:700;padding:1px 5px;
  border-radius:10px;
}
.badge-high{background:var(--green-dim);color:var(--green);border:1px solid var(--green-border)}
.badge-mid{background:var(--yellow-dim);color:var(--yellow);border:1px solid var(--border)}
.badge-low{background:var(--red-dim);color:var(--red);border:1px solid var(--red-border)}
.badge-none{background:var(--bg4);color:var(--text3);border:1px solid var(--border)}
.tree-dir-children{display:none}
.tree-dir-children.open{display:block}

/* ── Scenario list ─────────────────────────────────────────────────────── */
.traj-item{
  padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--border);
  transition:background .1s;
}
.traj-item:hover{background:var(--bg3)}
.traj-item.active{background:var(--bg4)}
.traj-item .traj-header{display:flex;align-items:center;gap:6px;margin-bottom:3px}
.traj-id{font-size:10px;font-weight:700;color:var(--text3);font-family:var(--font-mono);flex-shrink:0}
.traj-title{font-size:12px;font-weight:600;color:var(--text);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.status-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.status-passed{background:var(--green)}
.status-failed{background:var(--red)}
.status-skipped{background:var(--yellow)}
.traj-meta{font-size:10px;color:var(--text3);display:flex;gap:8px}
.traj-tag{
  padding:1px 5px;border-radius:4px;
  background:var(--bg4);border:1px solid var(--border);
  font-size:10px;color:var(--text3);
}

/* ── Main panel ──────────────────────────────────────────────────────────── */
#main{flex:1;display:flex;flex-direction:column;overflow:hidden;background:var(--bg)}

/* ── Welcome / dashboard ─────────────────────────────────────────────────── */
#view-welcome{flex:1;overflow-y:auto;padding:32px}
.dashboard-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:32px}
.card{
  background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-lg);
  padding:20px;
}
.card .label{font-size:11px;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.card .value{font-size:32px;font-weight:700;color:var(--text)}
.card .sub{font-size:11px;color:var(--text3);margin-top:4px}
.section-title{font-size:14px;font-weight:600;color:var(--text);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--border)}

/* Big coverage bar */
.cov-bar-wrap{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;margin-bottom:32px}
.cov-bar-header{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:12px}
.cov-bar-title{font-size:14px;font-weight:600;color:var(--text)}
.cov-bar-pct{font-size:24px;font-weight:700}
.cov-bar-track{height:12px;background:var(--bg4);border-radius:6px;overflow:hidden}
.cov-bar-fill{height:100%;border-radius:6px;transition:width .5s ease}
.cov-bar-legend{display:flex;gap:16px;margin-top:8px;font-size:11px;color:var(--text2)}
.cov-bar-legend span{display:flex;align-items:center;gap:4px}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block}

/* Top files table */
.files-table{width:100%;border-collapse:collapse}
.files-table th{text-align:left;padding:8px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text2);border-bottom:1px solid var(--border);font-weight:600}
.files-table td{padding:7px 12px;border-bottom:1px solid var(--border);font-size:12px}
.files-table tr:hover td{background:var(--bg2);cursor:pointer}
.files-table .file-path{font-family:var(--font-mono);color:var(--blue)}
.mini-bar{height:4px;background:var(--bg4);border-radius:2px;overflow:hidden;min-width:80px}
.mini-bar-fill{height:100%;border-radius:2px}

/* ── Source view ─────────────────────────────────────────────────────────── */
#view-source{flex:1;display:flex;flex-direction:column;overflow:hidden;display:none}
.source-header{
  padding:10px 16px;background:var(--bg2);border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:12px;flex-shrink:0;
}
.source-filepath{font-family:var(--font-mono);font-size:12px;color:var(--blue);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.source-stats{display:flex;gap:8px;flex-shrink:0}
.source-stat{font-size:11px;color:var(--text2)}
.source-body{flex:1;overflow:auto;font-family:var(--font-mono);font-size:12.5px;line-height:1.6}
.src-line{
  display:flex;min-height:20px;
  border-left:2px solid transparent;
  transition:background .1s;
}
.src-line:hover{background:var(--bg3)!important}
.src-line.covered{background:var(--green-dim);border-left-color:var(--green-border)}
.src-line.uncovered{background:var(--red-dim);border-left-color:var(--red-border)}
.src-line .ln{
  color:var(--text3);padding:0 12px 0 8px;min-width:50px;text-align:right;
  user-select:none;flex-shrink:0;border-right:1px solid var(--border);
  font-size:11px;line-height:20px;
}
.src-line .code{padding:0 8px;flex:1;white-space:pre;overflow:hidden}
.src-line .traj-badges{
  display:flex;gap:3px;align-items:center;padding:0 8px;flex-shrink:0;flex-wrap:nowrap;overflow:hidden;
}
.traj-badge{
  font-size:9px;font-weight:700;padding:1px 5px;border-radius:4px;cursor:pointer;
  background:rgba(88,166,255,.15);color:var(--blue);border:1px solid rgba(88,166,255,.3);
  white-space:nowrap;flex-shrink:0;
}
.traj-badge:hover{background:rgba(88,166,255,.3)}
.traj-badge.more{background:var(--bg4);color:var(--text3);border-color:var(--border)}

/* ── Scenario detail view ──────────────────────────────────────────────── */
#view-traj{flex:1;flex-direction:column;overflow-y:auto;padding:24px;display:none}
.traj-detail-header{margin-bottom:24px}
.traj-detail-header h2{font-size:20px;font-weight:700;color:var(--text);margin-bottom:6px}
.traj-detail-header .desc{color:var(--text2);font-size:13px;line-height:1.6;margin-bottom:12px}
.traj-detail-header .meta-row{display:flex;gap:12px;flex-wrap:wrap}
.traj-detail-header .pill{
  padding:3px 10px;border-radius:20px;border:1px solid var(--border);
  font-size:11px;color:var(--text2);background:var(--bg3);
}
.traj-files-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:12px}
.traj-file-card{
  background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);
  padding:12px;cursor:pointer;transition:border-color .15s;
}
.traj-file-card:hover{border-color:var(--blue)}
.traj-file-card .fname{font-family:var(--font-mono);font-size:12px;color:var(--blue);margin-bottom:6px;word-break:break-all}
.traj-file-card .fstats{font-size:11px;color:var(--text2)}

/* ── Tooltip ─────────────────────────────────────────────────────────────── */
#tooltip{
  position:fixed;z-index:9999;background:var(--bg2);border:1px solid var(--border);
  border-radius:var(--radius);padding:8px 12px;font-size:12px;color:var(--text);
  pointer-events:none;display:none;box-shadow:0 8px 24px rgba(0,0,0,.5);
  max-width:320px;
}
#tooltip .tt-title{font-weight:700;color:var(--blue);margin-bottom:3px;font-size:12px}
#tooltip .tt-desc{color:var(--text2);font-size:11px;line-height:1.4}

/* ── Scrollbar ───────────────────────────────────────────────────────────── */
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--bg4);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--text3)}
</style>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────────────────── -->
<div id="header">
  <h1><span class="icon">🎯</span> Scenario Coverage</h1>
  <div class="coverage-ring" id="header-ring">
    <span id="header-pct">…</span>
  </div>
  <div class="stat-pill"><span>Fichiers</span><span class="val" id="h-files">…</span></div>
  <div class="stat-pill"><span>Scénarios</span><span class="val" id="h-traj">…</span></div>
  <div class="stat-pill"><span>Réussies</span><span class="val" id="h-passed">…</span></div>
  <div class="header-spacer"></div>
  <span class="gen-date" id="h-date"></span>
</div>

<!-- ── App ─────────────────────────────────────────────────────────────── -->
<div id="app">

  <!-- Sidebar -->
  <div id="sidebar">
    <div class="sidebar-tabs">
      <div class="sidebar-tab active" data-tab="files">📁 Fichiers</div>
      <div class="sidebar-tab" data-tab="traj">🎯 Scénarios</div>
    </div>
    <div class="sidebar-panel active" id="tab-files"></div>
    <div class="sidebar-panel" id="tab-traj"></div>
  </div>

  <!-- Main -->
  <div id="main">
    <div id="view-welcome"></div>
    <div id="view-source"></div>
    <div id="view-traj"></div>
  </div>
</div>

<!-- Tooltip -->
<div id="tooltip"><div class="tt-title" id="tt-title"></div><div class="tt-desc" id="tt-desc"></div></div>

<script>
// ── Data ──────────────────────────────────────────────────────────────────
const DATA = __DATA_JSON__;

// ── State ─────────────────────────────────────────────────────────────────
let activeFile    = null;
let activeTrajId  = null;
let activeFileEl  = null;
let activeTrajEl  = null;

// ── Helpers ───────────────────────────────────────────────────────────────
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

function pctColor(pct) {
  if (pct >= 80) return 'var(--green)';
  if (pct >= 50) return 'var(--yellow)';
  return 'var(--red)';
}
function pctClass(pct) {
  if (pct === undefined) return 'badge-none';
  if (pct >= 80) return 'badge-high';
  if (pct >= 50) return 'badge-mid';
  if (pct > 0)   return 'badge-low';
  return 'badge-none';
}
function relPath(abs) {
  const root = DATA.srcRoot || '';
  // Le séparateur final évite qu'un root 'src' tronque mal un chemin 'srctest/…'.
  return root && abs.startsWith(root + '/') ? abs.slice(root.length + 1) : abs;
}
function shortId(fqcn) {
  const p = fqcn.split('\\');
  return p[p.length - 1];
}

// ── Header ────────────────────────────────────────────────────────────────
function initHeader() {
  const s = DATA.stats;
  const pct = s.globalPercent;
  document.getElementById('header-ring').style.setProperty('--pct', pct);
  document.getElementById('header-pct').textContent = pct + '%';
  document.getElementById('h-files').textContent   = s.files;
  document.getElementById('h-traj').textContent    = s.scenarios;
  document.getElementById('h-passed').textContent  = s.passed + '/' + s.scenarios;
  document.getElementById('h-date').textContent    = DATA.generatedAt;
}

// ── Welcome / Dashboard ───────────────────────────────────────────────────
function renderWelcome() {
  const s   = DATA.stats;
  const pct = s.globalPercent;
  const color = pctColor(pct);

  // Top 10 fichiers les moins couverts
  const topFiles = Object.entries(DATA.files)
    .sort((a, b) => a[1].percent - b[1].percent)
    .slice(0, 12);

  let tableRows = topFiles.map(([f, st]) => {
    const rp   = relPath(f);
    const c    = pctColor(st.percent);
    return `<tr class="file-row" data-file="${escHtml(f)}" title="${escHtml(f)}">
      <td class="file-path">${escHtml(rp)}</td>
      <td style="width:100px"><div class="mini-bar"><div class="mini-bar-fill" style="width:${st.percent}%;background:${c}"></div></div></td>
      <td style="width:40px;text-align:right;font-weight:700;color:${c}">${st.percent}%</td>
      <td style="width:60px;color:var(--text2)">${st.covered}/${st.total}</td>
    </tr>`;
  }).join('');

  const uncovered = Object.values(DATA.files).filter(f => f.percent === 0).length;
  const full      = Object.values(DATA.files).filter(f => f.percent === 100).length;

  document.getElementById('view-welcome').innerHTML = `
    <div class="cov-bar-wrap">
      <div class="cov-bar-header">
        <span class="cov-bar-title">Couverture globale par scénarios</span>
        <span class="cov-bar-pct" style="color:${color}">${pct}%</span>
      </div>
      <div class="cov-bar-track"><div class="cov-bar-fill" style="width:${pct}%;background:${color}"></div></div>
      <div class="cov-bar-legend">
        <span><span class="dot" style="background:var(--green)"></span>${s.globalCovered} lignes couvertes</span>
        <span><span class="dot" style="background:var(--red)"></span>${s.globalTotal - s.globalCovered} non couvertes</span>
        <span><span class="dot" style="background:var(--text3)"></span>${s.globalTotal} total</span>
      </div>
    </div>

    <div class="dashboard-cards">
      <div class="card">
        <div class="label">Fichiers analysés</div>
        <div class="value">${s.files}</div>
        <div class="sub">${full} à 100% · ${uncovered} non couverts</div>
      </div>
      <div class="card">
        <div class="label">Scénarios</div>
        <div class="value">${s.scenarios}</div>
        <div class="sub">${s.passed} passées · ${s.scenarios - s.passed} en échec/skip</div>
      </div>
      <div class="card">
        <div class="label">Couverture</div>
        <div class="value" style="color:${color}">${pct}%</div>
        <div class="sub">${s.globalCovered} / ${s.globalTotal} lignes exécutables</div>
      </div>
    </div>

    <div class="section-title">Fichiers — couverture la plus basse</div>
    <table class="files-table">
      <thead><tr><th>Fichier</th><th>Couverture</th><th>%</th><th>Lignes</th></tr></thead>
      <tbody>${tableRows}</tbody>
    </table>`;
}

// ── File tree ─────────────────────────────────────────────────────────────
function renderFileTree(node, container, depth = 0) {
  const indent = depth * 14;
  Object.entries(node).sort((a, b) => {
    // Dossiers avant fichiers, puis alphabétique
    const aIsFile = '__file' in a[1];
    const bIsFile = '__file' in b[1];
    if (aIsFile !== bIsFile) return aIsFile ? 1 : -1;
    return a[0].localeCompare(b[0]);
  }).forEach(([name, val]) => {
    if ('__file' in val) {
      // Fichier
      const f   = val.__file;
      const st  = val.__stats;
      const pct = st.percent;
      const el  = document.createElement('div');
      el.className = 'tree-item';
      el.dataset.file = f;
      el.innerHTML = `
        <span class="indent" style="width:${indent}px;display:inline-block"></span>
        <span class="icon">📄</span>
        <span class="name" title="${escHtml(f)}">${escHtml(name)}</span>
        <span class="badge ${pctClass(pct)}">${pct}%</span>`;
      el.addEventListener('click', () => openFile(f, el));
      container.appendChild(el);
    } else {
      // Dossier
      const togId = 'dir-' + Math.random().toString(36).slice(2);
      // Calculer le % agrégé du dossier
      const pct = calcDirPct(val);
      const el  = document.createElement('div');
      el.className = 'tree-item';
      el.innerHTML = `
        <span class="indent" style="width:${indent}px;display:inline-block"></span>
        <span class="icon dir-icon">📂</span>
        <span class="name">${escHtml(name)}</span>
        <span class="badge ${pctClass(pct)}">${pct !== undefined ? pct + '%' : ''}</span>`;
      container.appendChild(el);

      const children = document.createElement('div');
      children.className = 'tree-dir-children open';
      children.id = togId;
      container.appendChild(children);
      renderFileTree(val, children, depth + 1);

      el.addEventListener('click', (e) => {
        e.stopPropagation();
        children.classList.toggle('open');
        el.querySelector('.dir-icon').textContent = children.classList.contains('open') ? '📂' : '📁';
      });
    }
  });
}

function calcDirPct(node) {
  let cov = 0, tot = 0;
  const walk = (n) => {
    Object.values(n).forEach(v => {
      if ('__file' in v) { cov += v.__stats.covered; tot += v.__stats.total; }
      else walk(v);
    });
  };
  walk(node);
  return tot > 0 ? Math.round((cov / tot) * 100) : undefined;
}

// ── Scenario list ───────────────────────────────────────────────────────
function renderTrajList() {
  const container = document.getElementById('tab-traj');
  container.innerHTML = '';
  DATA.scenarios.forEach(t => {
    const el = document.createElement('div');
    el.className = 'traj-item';
    el.dataset.id = t.id;
    const tags = (t.tags || []).map(tag => `<span class="traj-tag">${escHtml(tag)}</span>`).join('');
    el.innerHTML = `
      <div class="traj-header">
        <span class="status-dot status-${t.status}"></span>
        <span class="traj-id">${escHtml(t.id)}</span>
        <span class="traj-title">${escHtml(t.title)}</span>
      </div>
      <div class="traj-meta">
        <span>⏱ ${t.duration}s</span>
        <span>📄 ${(t.files || []).length} fichiers</span>
        ${tags}
        ${t.mandatory ? '<span class="traj-tag" style="color:var(--orange);border-color:var(--orange)">obligatoire</span>' : ''}
      </div>`;
    el.addEventListener('click', () => openScenario(t.id, el));
    container.appendChild(el);
  });
}

// ── Open file ─────────────────────────────────────────────────────────────
function openFile(filePath, treeEl) {
  activeFile = filePath;

  // Highlight tree
  if (activeFileEl) activeFileEl.classList.remove('active');
  if (treeEl) { treeEl.classList.add('active'); activeFileEl = treeEl; }
  // Sync sidebar to files tab if needed
  switchSidebarTab('files');

  const st      = DATA.files[filePath] || {};
  const lines   = DATA.fileLines[filePath] || {};
  const srcLines = DATA.sources[filePath] || [];
  const rel     = relPath(filePath);

  let linesHtml = '';
  srcLines.forEach((codeHtml, i) => {
    const ln    = i + 1;
    const tids  = lines[ln] || [];  // [] = uncovered, [...] = covered
    const hasCovData = ln in lines;
    const isCovered  = tids.length > 0;

    let cls = '';
    if (hasCovData) cls = isCovered ? 'covered' : 'uncovered';

    // Badges scénario (max 3 + "+N")
    let badges = '';
    if (isCovered) {
      const shown = tids.slice(0, 3);
      const rest  = tids.length - shown.length;
      badges = shown.map(tid => `<span class="traj-badge" data-tid="${escHtml(tid)}">${escHtml(tid)}</span>`).join('');
      if (rest > 0) badges += `<span class="traj-badge more">+${rest}</span>`;
    }

    linesHtml += `<div class="src-line ${cls}" data-ln="${ln}">
      <span class="ln">${ln}</span>
      <span class="code">${codeHtml || ' '}</span>
      <span class="traj-badges">${badges}</span>
    </div>`;
  });

  const color = pctColor(st.percent || 0);
  document.getElementById('view-source').innerHTML = `
    <div class="source-header">
      <span class="source-filepath" title="${escHtml(filePath)}">${escHtml(rel)}</span>
      <div class="source-stats">
        <span class="source-stat">
          <strong style="color:${color}">${st.percent || 0}%</strong> couverts
        </span>
        <span class="source-stat">${st.covered || 0}/${st.total || 0} lignes</span>
        <span class="source-stat">${(st.scenarios || []).length} scénarios</span>
      </div>
    </div>
    <div class="source-body">${linesHtml}</div>`;

  showView('source');
}

// ── Open scenario ───────────────────────────────────────────────────────
function openScenario(id, listEl) {
  const traj = DATA.scenarios.find(t => t.id === id);
  if (!traj) return;
  activeTrajId = id;

  if (activeTrajEl) activeTrajEl.classList.remove('active');
  if (listEl) { listEl.classList.add('active'); activeTrajEl = listEl; }
  switchSidebarTab('traj');

  const tags = (traj.tags || []).map(t => `<span class="pill">${escHtml(t)}</span>`).join('');
  const files = (traj.files || []);
  const filesHtml = files.map(f => {
    const st  = DATA.files[f] || {};
    const rel = relPath(f);
    // Lignes couvertes par CETTE scénario dans ce fichier
    const fileLinesData = DATA.fileLines[f] || {};
    const trajLines = Object.values(fileLinesData).filter(tids => tids.includes(id)).length;
    return `<div class="traj-file-card" data-file="${escHtml(f)}" title="${escHtml(f)}">
      <div class="fname">${escHtml(rel)}</div>
      <div class="fstats">${trajLines} lignes couvertes · fichier à ${st.percent || 0}% global</div>
    </div>`;
  }).join('');

  document.getElementById('view-traj').innerHTML = `
    <div class="traj-detail-header">
      <h2>${escHtml(traj.title)}</h2>
      <div class="desc">${escHtml(traj.description)}</div>
      <div class="meta-row">
        <span class="pill" style="color:var(--${traj.status === 'passed' ? 'green' : traj.status === 'failed' ? 'red' : 'yellow'})">
          ${traj.status}
        </span>
        <span class="pill">⏱ ${traj.duration}s</span>
        <span class="pill">📄 ${files.length} fichiers touchés</span>
        ${traj.mandatory ? '<span class="pill" style="color:var(--orange)">⚑ obligatoire</span>' : ''}
        ${tags}
      </div>
    </div>
    <div class="section-title">Fichiers couverts par cette scénario</div>
    ${files.length === 0
      ? '<p style="color:var(--text2);font-size:13px">Aucun fichier couvert (coverage non disponible ou xdebug absent).</p>'
      : `<div class="traj-files-grid">${filesHtml}</div>`}`;

  showView('traj');
}

function openTrajById(id) {
  // Trouver et cliquer sur l'item de la liste
  const listEl = document.querySelector(`#tab-traj [data-id="${CSS.escape(id)}"]`);
  openScenario(id, listEl);
}

// ── Views ─────────────────────────────────────────────────────────────────
function showView(name) {
  $$('#main > div').forEach(el => el.style.display = 'none');
  const map = { welcome: 'view-welcome', source: 'view-source', traj: 'view-traj' };
  const el = document.getElementById(map[name]);
  if (el) el.style.display = 'flex';
}

// ── Sidebar tabs ──────────────────────────────────────────────────────────
function switchSidebarTab(name) {
  $$('.sidebar-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
  $$('.sidebar-panel').forEach(p => p.classList.toggle('active', p.id === 'tab-' + name));
}

// ── Tooltip on scenario badges ──────────────────────────────────────────
const tooltip = document.getElementById('tooltip');
document.addEventListener('mouseover', e => {
  const badge = e.target.closest('.traj-badge[data-tid]');
  if (!badge) { tooltip.style.display = 'none'; return; }
  const tid  = badge.dataset.tid;
  const traj = DATA.scenarios.find(t => t.id === tid);
  if (!traj) return;
  document.getElementById('tt-title').textContent = traj.id + ' — ' + traj.title;
  document.getElementById('tt-desc').textContent  = traj.description;
  tooltip.style.display = 'block';
});
document.addEventListener('mousemove', e => {
  tooltip.style.left = (e.clientX + 12) + 'px';
  tooltip.style.top  = (e.clientY + 12) + 'px';
});
document.addEventListener('mouseout', e => {
  // Masquer dès qu'on quitte un badge, quelle que soit la destination du curseur
  // (y compris hors de la fenêtre, où aucun mouseover ne viendrait le cacher).
  if (e.target.closest('.traj-badge[data-tid]')) tooltip.style.display = 'none';
});

// ── Sidebar tab click ─────────────────────────────────────────────────────
$$('.sidebar-tab').forEach(tab => {
  tab.addEventListener('click', () => switchSidebarTab(tab.dataset.tab));
});

// ── Délégation des clics ────────────────────────────────────────────────────
// Remplace les anciens handlers inline (qui ré-interprétaient en JS les chemins/ids
// HTML-encodés → cassure ou injection si un chemin contient une apostrophe).
// Ici la valeur reste un attribut data-* échappé, jamais ré-évaluée comme code.
document.addEventListener('click', e => {
  const badge = e.target.closest('.traj-badge[data-tid]');
  if (badge) { e.stopPropagation(); openTrajById(badge.dataset.tid); return; }
  const opener = e.target.closest('.file-row, .traj-file-card');
  if (opener) openFile(opener.dataset.file);
});

// ── Escape helper ─────────────────────────────────────────────────────────
function escHtml(str) {
  return String(str)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── Init ──────────────────────────────────────────────────────────────────
initHeader();
renderWelcome();

// File tree
const treeContainer = document.getElementById('tab-files');
renderFileTree(DATA.tree, treeContainer);

// Scenario list
renderTrajList();

// Show welcome by default
showView('welcome');
document.getElementById('view-welcome').style.display = 'block';
</script>
</body>
</html>
HTML;

        // Injection des deux valeurs PHP dans le nowdoc
        return str_replace(
            ['__PROJECT_NAME__', '__DATA_JSON__'],
            [htmlspecialchars($projectName, ENT_QUOTES | ENT_SUBSTITUTE), $dataJson],
            $html
        );
    }
}
