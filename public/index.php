<?php
declare(strict_types=1);

// Firmennetz: die Seite darf NICHTS von aussen laden und nirgends ausser zu sich selbst senden.
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$v = static fn(string $f): int => (int)@filemtime(__DIR__ . '/' . $f);
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title>SchmierPAD</title>
	<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
	<link rel="stylesheet" href="assets/app.css?v=<?= $v('assets/app.css') ?>">
</head>
<body>
	<header class="header">
		<div class="header-top">
			<div class="title-group">
				<h1>SchmierPAD</h1>
				<span class="title-sep" aria-hidden="true">|</span>
				<span class="workplace">Arbeitsplatz <span id="macLabel">…</span></span>
			</div>
			<div class="header-tools">
				<button type="button" id="exportBtn" class="tool-btn" title="Liste als Datei sichern (für einen PC-Wechsel)">Export</button>
				<button type="button" id="importBtn" class="tool-btn" title="Liste aus einer Export-Datei laden (nur in eine leere Liste)">Import</button>
				<button type="button" id="infoBtn" class="tool-btn icon-btn" data-open-dialog="infoModal" title="Info zu Export und Import" aria-label="Info zu Export und Import">
					<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/><path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0"/></svg>
				</button>
				<input type="file" id="importFile" accept=".json,application/json" hidden>
			</div>
		</div>
		<form id="addForm" class="add-row" autocomplete="off">
			<input type="text" id="newTitle" maxlength="500" placeholder="Task eintragen ..." aria-label="Neuer Task">
			<button type="submit" class="add-btn">Hinzufügen</button>
		</form>
		<div class="prio-row">
			<button type="button" id="prioInfoBtn" class="tool-btn icon-btn small" data-open-dialog="prioModal" title="Was bedeuten die Prioritäten?" aria-label="Info zu den Prioritäten">
				<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/><path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0"/></svg>
			</button>
			<span class="prio-label">Priorität</span>
			<div class="prio-picker" id="newPrio" role="radiogroup" aria-label="Priorität"></div>		</div>
	</header>

	<main>
		<section id="urgentSection" class="list-section urgent" hidden>
			<h2>Dringend</h2>
			<ul id="urgentList"></ul>
		</section>
		<section class="list-section">
			<h2 id="mainHeading">Aufgaben</h2>
			<ul id="mainList"></ul>
			<p id="emptyHint" class="empty-hint" hidden>Noch nichts eingetragen.</p>
		</section>
	</main>

	<div id="prioPopover" class="prio-popover" hidden>
		<div class="prio-picker" id="popPicker"></div>
	</div>

	<div id="modal" class="modal" hidden>
		<div class="modal-box" role="alertdialog" aria-modal="true" aria-labelledby="modalTitle">
			<h3 id="modalTitle"></h3>
			<p id="modalText"></p>
			<div class="modal-actions"><button type="button" id="modalOk" class="add-btn">OK</button></div>
		</div>
	</div>

	<div id="infoModal" class="modal info-dialog" hidden>
		<div class="modal-box info-box" role="dialog" aria-modal="true" aria-labelledby="infoTitle">
			<h3 id="infoTitle">Export &amp; Import – so ist es gedacht</h3>

			<h4>Wozu gibt es das?</h4>
			<p>Deine Liste gehört zu <strong>diesem Arbeitsplatz-PC</strong> (genauer: zu seiner Netzwerkkarte), nicht zu einem Benutzer. Es gibt keine Anmeldung, und an anderen PCs siehst du sie nicht. Export und Import sind nur für den <strong>Umzug auf einen neuen PC</strong> gedacht – damit du deine Liste mitnehmen kannst.</p>

			<h4>Export</h4>
			<p>Speichert alle deine Aufgaben als Datei (<em>schmierpad-export-JJJJ-MM-TT.json</em>) – mit Priorität, Erstelldatum und Erledigt-Status. Deine Liste bleibt dabei unverändert. Du kannst jederzeit exportieren, z. B. als Sicherung.</p>

			<h4>Import</h4>
			<p>Lädt eine solche Export-Datei in die Liste. Erstelldatum, Priorität und Erledigt-Status bleiben erhalten.</p>
			<p><strong>Der Import funktioniert nur in eine leere Liste</strong> – also z. B. am neuen PC, an dem du noch nichts angelegt hast.</p>

			<h4>Wichtig: nichts wird zusammengeführt oder ersetzt</h4>
			<ul>
				<li>Ist auf diesem PC schon eine Liste vorhanden, wird der Import <strong>abgelehnt</strong> – mit einem Hinweis.</li>
				<li>Bestehende Aufgaben werden weder mit einer Import-Datei <strong>zusammengeführt</strong> noch davon <strong>überschrieben</strong>.</li>
				<li>Auch die Liste einer anderen Person kann nicht in eine bestehende Liste importiert werden. Bitte solche Einträge <strong>manuell anlegen</strong>.</li>
			</ul>

			<h4>So ziehst du um</h4>
			<ol>
				<li>Am <strong>alten PC</strong>: auf <em>Export</em> klicken und die Datei speichern.</li>
				<li>Die Datei auf den <strong>neuen PC</strong> kopieren (z. B. per USB-Stick oder Netzlaufwerk).</li>
				<li>Am neuen PC SchmierPAD öffnen (die Liste ist dort noch leer) und auf <em>Import</em> klicken.</li>
			</ol>

			<h4>Gut zu wissen</h4>
			<ul>
				<li>SchmierPAD funktioniert nur innerhalb des Unternehmens (Firmennetz).</li>
				<li>Ein Notebook hat für Kabel (LAN) und WLAN je eine eigene Netzwerkkarte – dort gibt es dann zwei getrennte Listen.</li>
				<li>Aufgaben lassen sich erst löschen, wenn sie als erledigt markiert sind.</li>
			</ul>

			<div class="modal-actions"><button type="button" class="add-btn" data-close-dialog>Schließen</button></div>
		</div>
	</div>

	<div id="prioModal" class="modal info-dialog" hidden>
		<div class="modal-box info-box" role="dialog" aria-modal="true" aria-labelledby="prioTitle">
			<h3 id="prioTitle">Prioritäten – Zahl, Farbe, Bedeutung</h3>
			<p>Jede Aufgabe hat eine Priorität von <strong>1 (am wichtigsten)</strong> bis <strong>8 (am unwichtigsten)</strong>. Beim Anlegen ist <strong>6</strong> voreingestellt. Die Priorität lässt sich später jederzeit ändern: einfach auf das farbige Feld rechts an der Aufgabe klicken.</p>

			<h4>Rottöne – stehen oben in der Liste „Dringend“</h4>
			<ul class="prio-legend">
				<li><span class="prio-dot p1">1</span><div><strong>Jetzt sofort</strong><span>Signalrot – die Zeile pulsiert leicht. Alles andere kann warten.</span></div></li>
				<li><span class="prio-dot p2">2</span><div><strong>Extrem wichtig</strong><span>Dunkelrot</span></div></li>
				<li><span class="prio-dot p3">3</span><div><strong>Sehr wichtig</strong><span>Rot</span></div></li>
				<li><span class="prio-dot p4">4</span><div><strong>Wichtig</strong><span>Hellrot</span></div></li>
			</ul>

			<h4>Gelbtöne – sollte demnächst angegangen werden</h4>
			<ul class="prio-legend">
				<li><span class="prio-dot p5">5</span><div><strong>Bald</strong><span>Orange-Gelb</span></div></li>
				<li><span class="prio-dot p6">6</span><div><strong>Demnächst</strong><span>Gelb – die Voreinstellung für neue Aufgaben</span></div></li>
			</ul>

			<h4>Grüntöne – „ja, wenn mal Zeit ist“</h4>
			<ul class="prio-legend">
				<li><span class="prio-dot p7">7</span><div><strong>Bei Gelegenheit</strong><span>Hellgrün</span></div></li>
				<li><span class="prio-dot p8">8</span><div><strong>Wenn mal Zeit ist</strong><span>Grün</span></div></li>
			</ul>

			<p class="legend-note">Aufgaben mit Priorität 5 bis 8 stehen darunter in der Liste „Weitere Aufgaben“. Innerhalb einer Liste steht die höhere Priorität oben, bei gleicher Priorität die ältere Aufgabe.</p>

			<div class="modal-actions"><button type="button" class="add-btn" data-close-dialog>Schließen</button></div>
		</div>
	</div>

	<script src="assets/app.js?v=<?= $v('assets/app.js') ?>"></script>
</body>
</html>
