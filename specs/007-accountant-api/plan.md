# Implementation Plan: Vollständige Buchhalter-API

**Branch**: `deployment` | **Feature ID**: `007-accountant-api` | **Date**: 2026-09-10 | **Stand**: 2026-09-14 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/007-accountant-api/spec.md`

## Summary

Die bestehende `/api/v1` wird etappenweise zu einem vollständigen Arbeitskanal für einen organisationsgebundenen Buchhalter ausgebaut. Alle neuen Erweiterungen werden im eigenständig aktivierbaren Gäld-Modul `plugins/accountant-api` mit Namespace `Plugins\AccountantApi` implementiert. Die bereits vorhandenen Kernendpunkte bleiben an Ort und Stelle; das Modul registriert nur zusätzliche, kollisionsfreie `/api/v1`-Routen. Zusammen bilden Kern-API und Modul den öffentlichen Vertrag.

Das Modul bleibt eine dünne, versionierte Transportschicht: Es validiert und autorisiert, ruft dieselben Domain-Actions und Query-Services wie die Weboberfläche über zentralisierte Core-Bridge-Adapter auf und transformiert Ergebnisse mit modul-eigenen API-Resources. Fachlogik und fachliche Daten bleiben im Kern. Wenn ein benötigter Anwendungsfall im Kern noch nur in einem Webcontroller steckt, wird zuerst eine kleine generische Domain-Action oder ein anderer stabiler Core-Seam geschaffen. Diese Kernänderung wird getrennt vom Modulcommit gehalten und soll upstream-fähig sein.

Die Umsetzung beginnt mit Modulgerüst, Kompatibilitätsgrenze sowie einem verbindlichen Sicherheits- und Vertragsfundament. Danach folgen Stammdaten, Tagesbuchhaltung, Bankabstimmung, Reports, Abschluss, Payroll und optionale Fachmodule. Jede Etappe ist separat testbar, ausrollbar und rückrollbar. Kern- und Moduländerungen laufen dabei in zwei getrennten Lieferströmen.

## Aktueller Umfang: Budget-API abschliessen (ab 2026-09-14)

**Entscheid vom 2026-09-14**: Die Arbeit an dieser Spezifikation beschränkt
sich vorerst auf die Budget-Erweiterung. Sie soll in sich geschlossen
funktionieren — setzen, lesen, auswerten, löschen — und nicht auf eine
spätere Etappe warten müssen. Die Etappen 1 und 3–9 sind damit **geparkt**,
nicht verworfen: ihre Beschreibungen weiter unten bleiben als Zielbild
stehen, aber es wird nichts daraus gebaut, bis dieser Entscheid geändert
wird.

Das ist möglich, weil Budgets an keiner offenen Vorarbeit hängen. Weder die
zurückgestellten Etappe-0-Punkte (T014, T017) noch eine der Nacharbeiten aus
dem Korrektur-Paket berühren sie: ein Budget ist direkt organisationsgebunden,
der Upsert löst die Parallelitätsfrage von selbst, und die Korrektur-Themen
liegen in einer anderen Domäne.

**Was „geschlossen" heisst** — der Abnahmetor steht in
[tasks-budgets.md](tasks-budgets.md):

- Sollwerte je Konto und Geschäftsjahr per API setzen, lesen, ändern, löschen. **Erledigt.**
- Den Soll-Ist-Vergleich per API lesen, mit Abweichung absolut und in Prozent. **Erledigt.**
- Eine Budgetänderung wirkt sich sofort auf Berichte aus, statt bis zu 30 Minuten im Cache hängenzubleiben. **Erledigt** — die Korrektur behebt denselben Fehler auch in der Weboberfläche.
- Web und API lesen und schreiben dieselbe Quelle, nachgewiesen an einer laufenden Anwendung. **Erledigt**, für Pflege und Vergleich.

**Ausdrücklich nicht im Umfang**: Sammel-Upsert für ein ganzes Jahr (pro Konto
ein Aufruf genügt), Budget-Webhooks, Budgetversionen oder -szenarien,
`updated_since`-Synchronisation und jeder weitere Bericht ausser dem
Soll-Ist-Vergleich auf Budgets.

## Umsetzungsstand (Stand 2026-09-14)

Dieser Abschnitt hält fest, was vom Plan tatsächlich geliefert ist. Der
vollständige Task-Verlauf mit allen Abweichungen steht in
[tasks.md](tasks.md); jene Datei deckt bewusst nur Etappe 0 und das
Korrektur-Fokuspaket ab. Die Etappenbeschreibungen weiter unten bleiben als
Zielbild unverändert.

| Etappe | Stand |
|---|---|
| 0 – Modul-, Sicherheits- und Vertragsfundament | **Abgeschlossen**, Gate erfüllt; zwei bewusste Rückstellungen (siehe unten) |
| 1 – Read-Modell und Stammdaten | offen, noch kein `tasks.md` |
| 2 – Tagesbuchhaltung | **teilweise**, Rest geparkt: Fokuspaket *Geführte Journal-Korrektur* vollständig geliefert (Kern, Web, API); Rechnungs- und Ausgabenworkflow offen |
| 3 – Banking und Abstimmung | **geparkt** |
| 4 – Reports und Exporte | **geparkt** |
| 5 – MWST und Periodenabschluss | **geparkt** |
| 6 – Anlagen, Jahresabschluss, Archiv | **geparkt** |
| 7 – Payroll | **geparkt** |
| 8 – Optionale Fachmodule (Budgets, Kostenstellen, Fremdwährung, Steuerdeklarationen, Konsolidierung) | **teilweise**: Fokuspaket **Budgets** vorgezogen und **abgeschlossen**, siehe [tasks-budgets.md](tasks-budgets.md). Kostenstellen, Fremdwährung, Steuerdeklarationen und Konsolidierung geparkt |
| 9 – Pilot, Härtung, Upstream-Kompatibilität | **geparkt** |

**Geliefert (Etappe 0)**: `plugins/accountant-api` mit Manifest, Service
Provider, Routen, Migrationen, Übersetzungen und Tests; der von
`EditionCompatibility` unabhängige `PluginContractVersion`; fail-closed
Manifestprüfung; Allow-List `PLUGINS_ALLOWED`; `RouteCollisionGuard`;
Architektur-Grenztest; die registrierbaren Seams `AbilityCatalog` und
`WebhookEventCatalog`; gehärtete Idempotenz gegen Prozessabbruch;
Request-Kontext (Quelle, Token, Correlation- und Idempotency-Key) im
Activity-Log; Modulvertrag `contract.json` samt Abgleichstest; Boot-/Smoke-Matrix.

**Geliefert (Fokuspaket Etappe 2)**: `journal_corrections` und
`journal_entries.reversal_of_entry_id` als Track-A-Kernmigrationen;
Eligibility-Service sowie die Actions Prepare/Post/Cancel; `correct`-Ability in
`JournalEntryPolicy`; Ereignis `journal_entry.corrected` inklusive
Webhook-Auslieferung; die fünf Endpunkte unter `/api/v1/journal-entries/{id}/corrections`
und `/api/v1/journal-corrections/{id}`; der Korrekturdialog in der Journalansicht
in allen vier Sprachen. Der Ablauf ist Ende zu Ende gegen eine laufende
Anwendung geprüft — manuelle, importierte und API-erzeugte Buchungen — und wird
seither im Betrieb verwendet.

**Bewusst zurückgestellt, nicht vergessen**:

- T014 – tenant-sichere verschachtelte Bindings: ohne Konsument nicht gebaut, wird für Etappe 3 (Banking) gebraucht.
- T017 – optimistische Parallelitätskontrolle (`409`/`412`): bewusst nicht nur für zwei Endpunkte eingeführt; vor Etappe 3 als Vertragsentscheid nachzuholen.

**Offene Nacharbeiten aus dem Fokuspaket**:

- Backfill von `journal_events.payload` für eindeutig verknüpfbare Alt-Stornos (aus T029) — Voraussetzung, bevor Etappe 5/6 auf die strukturierte Reversal-Beziehung baut.
- Unit-Tests für den Ausschluss bank-, lohn- und anlagenverknüpfter Buchungen; die Logik existiert, die Factories fehlten (T023).
- Datumsvorschlag für `correction_date`; heute reine Pflichteingabe (T024/T031).
- Webhook-Auslieferung auch für `journal_entry.posted` und `.reversed`; bestehende Lücke, nur `.corrected` ist verdrahtet (T051).
- `LogOrgTokenActivity` fehlt in der Routengruppe der Korrektur-Endpunkte; Aufrufe mit Organisationstoken werden dort nicht protokolliert (gefunden beim Budget-Paket, siehe tasks-budgets.md).
- `ApiIdempotencyService::reserve()` bildet den Idempotenzschlüssel ohne die konkreten Pfadparameter. Für Kernrouten heute folgenlos, weil deren Parameter implizite Modellbindungen sind; für Modulrouten mit reinen String-Parametern wäre der automatische Rückfall falsch. Vor Etappe 3 zu entscheiden, zusammen mit T017.

**Nächster Schritt**: Keiner. Die Budget-API ist seit 2026-09-14 geschlossen,
das Abnahmetor in [tasks-budgets.md](tasks-budgets.md) ist erfüllt und am
laufenden Stack nachgewiesen. Die Arbeit an dieser Spezifikation ruht bis zu
einem neuen Umfangsentscheid.

Sobald wieder aufgenommen wird, bleibt Etappe 1 (Read-Modell und Stammdaten)
der strukturell richtige nächste Block — sie ist die Voraussetzung dafür, dass
ein API-Client IDs und Konfigurationswerte selbst entdeckt, statt sie aus der
Weboberfläche abzuschreiben.

Unabhängig davon ist das **Fokuspaket Budgets** aus Etappe 8 vorgezogen und
seit 2026-09-14 geliefert, geplant und protokolliert in
[tasks-budgets.md](tasks-budgets.md). Es hängt an keiner der Etappen
1–7: `/api/v1/accounts` existiert lesend, `fiscal_year` ist eine schlichte
Jahreszahl, ein Budget hängt direkt an der Organisation, und die Adressierung
über den natürlichen Schlüssel `(account_code, fiscal_year)` vermeidet jede
neue Migration. Gleiches Vorgehen wie beim Korrektur-Fokuspaket, das aus
Etappe 2 vorgezogen wurde, während Etappe 1 offen blieb.

## Technical Context

**Language/Runtime**: PHP 8.4, Laravel 13

**Frontend**: Inertia.js v3, Vue 3, Vite, Tailwind CSS; bestehende Ansichten und Activity-Log dienen als Beobachtungskanal, die Journalansicht erhält zusätzlich einen geführten Korrekturdialog

**Storage/Infrastructure**: PostgreSQL, Redis, Horizon, lokaler Dateispeicher; bestehende Sanctum-Tokens, Idempotency Records, Audit-Log und Webhooks

**Testing**: PHPUnit durch `vendor/bin/sail artisan test`; PHPStan und Pint; Scribe/OpenAPI-Generierung und bestehende Contract-Tests

**Project Type**: Bestehende Laravel-Anwendung mit Domain-Modulen, Organisationstenancy, CE/EE-Feature-Flags, versionierter REST-API und vorhandenem dateibasiertem Plugin-System

**Performance and Scale**:

- Normale Listen bleiben paginiert und auf höchstens 100 Datensätze pro Seite begrenzt.
- Grosse Journale werden gestreamt oder in Chunks verarbeitet; vollständige Exporte laufen asynchron.
- Interaktive Stammdaten- und Detailabfragen sollen bei normaler Mandantengrösse im 95. Perzentil innerhalb von 2 Sekunden antworten.
- Frei wählbare Reportperioden erhalten separates Rate-Limiting und dürfen nicht zu ungebremsten kontoweisen Abfragen führen.

**Constraints**:

- `BelongsToOrganization` bleibt Quelle der Eloquent-Mandantentrennung; Raw Queries, Validierungsregeln und Jobs werden explizit organisationsgebunden.
- Token-Scope, Benutzerrolle, Objektzugehörigkeit und Domain-Invarianten müssen gemeinsam erfüllt sein.
- Gebuchte, abgeschlossene oder archivierte Datensätze werden nicht direkt verändert.
- Finanzielle Mutationen sind atomar und idempotent; Datei- und Massenoperationen sind retry-sicher.
- Geldbeträge werden als Dezimalstrings, Datumswerte als ISO-8601 und öffentliche IDs als UUIDs transportiert.
- Payroll- und Personaldaten werden ausschliesslich per expliziter Feld-Allow-List ausgegeben.
- Bestehende Services und Actions werden wiederverwendet; fehlende Invarianten werden in der jeweiligen Domain, nicht im API-Controller, ergänzt.
- Neue Endpunkte sind additive Erweiterungen von v1. Breaking Changes benötigen v2 oder eine vorgängige Deprecation.
- Modulcode liegt ausschliesslich unter `plugins/accountant-api`; der Kern enthält keine `AccountantApi`-Klassen und kennt den Modul-Namespace nicht.
- Das Modul ersetzt oder überschattet keine Kernroute, Kernklasse, Kerntabelle, Policy oder Konfiguration. Ein automatischer Route-Collision-Test erzwingt diese Regel.
- Direkte Zugriffe des Moduls auf Kernimplementierungen werden in `CoreBridge/` zentralisiert. Controller, Requests, Resources und Jobs importieren keine Kernmodelle oder konkreten Domain-Services direkt.
- Neue Core-Seams sind generisch, rückwärtskompatibel und separat commit-/cherry-pickbar. Kundenspezifische oder reine Transportlogik darf nicht in den Kern gelangen.
- Der Modulmanifest deklariert die benötigte Version des stabilen Core-Extension-Vertrags und die im CI verifizierten Gäld-Releases. Unbekannte oder inkompatible Vertragsversionen führen zu einer verweigerten Aktivierung mit Diagnose, nicht zu einem teilweisen Boot.
- Der Kern muss mit fehlendem sowie deaktiviertem Modul vollständig funktionieren. Fachliche Kerndaten dürfen beim Deaktivieren oder Entfernen des Moduls nicht verändert werden.

## Existing Codebase Impact

### Architekturentscheidung und Ownership

- **Accountant-API-Modul**: besitzt alle neuen Routen, Controller, Requests, Resources, Modul-Middleware, Ability-Katalogbeiträge, Idempotenz-Orchestrierung, API-Aufträge, Exportdownloads, Webhook-Adapter, Konfiguration, Übersetzungen, Vertrag und Modultests.
- **Bestehende `Api`-Domain**: besitzt weiterhin vorhandene `/api/v1`-Endpunkte, Sanctum-Tokens und die heute schon vorhandene API-Basis. Bestehender Code wird nicht im Rahmen dieses Vorhabens ins Modul verschoben.
- **Fachdomains**: Accounting, Invoicing, Expenses, Banking, Reporting, Assets und Payroll bleiben alleinige Eigentümer ihrer Fachlogik und Daten.
- **Kern-Extension-Points**: generische registrierbare Ability-/Webhook-Kataloge, eine sichere API-Organisationsauflösung und dokumentierte Domain-Actions/Queries, soweit diese für entkoppelte Module fehlen.
- **Invariants preserved**: ausgeglichene Journalbuchungen; Periodensperren; unveränderliche gebuchte/archivierte Datensätze; eindeutige Referenzen; gültige Statusübergänge; tenant-sichere Fremdschlüssel; Abschlussatomarität; kein doppelter Import oder Lohnlauf.
- **Existing services/actions to reuse**:
  - `LedgerService`, `LedgerQueryService`, `FiscalYearService`, `VatReportService`
  - vorhandene Journal-, Opening-Balance-, VAT-Settlement- und Year-End-Actions
  - vorhandene Invoice-Lifecycle-Actions und Invoice-Queries
  - Expense CRUD-, Approval- und Posting-Actions
  - `BankImportService` und vorhandene Reconciliation-Logik; vor API-Freigabe aus dem Webcontroller in Actions extrahieren
  - `ReportingService`, `AgingReportService`, `ExportReportService`, `AccountingExportService`
  - Asset- und Payroll-Actions einschliesslich `GeneratePayrollRunAction`, `PostPayrollAction` und `UnpostPayrollAction`
  - `ApiIdempotencyService`, `WebhookService`, `CurrentOrganization`, Policies und Feature Flags über dokumentierte Bridge-Adapter
- **Existing documentation and specs consulted**:
  - `specs/003-accounting-api/`
  - `contract/api-contract.json`
  - `app/Domains/Api/README.md`
  - `app/Domains/Reporting/README.md`
  - `app/Domains/Payroll/README.md`
  - `routes/api.php` und `routes/web/*.php`

### Zielstruktur des Moduls

```text
plugins/accountant-api/
├── plugin.json                          # Name, Version, Provider, Core-Kompatibilität
├── config/accountant-api.php            # Kill Switches, Limits, Queue/Storage
├── routes/api.php                       # nur additive /api/v1-Routen
├── migrations/                          # ausschliesslich Modul-Infrastruktur
├── contract/api-contract.json           # Modulanteil des öffentlichen Vertrags
├── lang/{de,en,fr,it}/api.php
├── src/AccountantApiServiceProvider.php
├── src/CoreBridge/                      # einziger Import-Ort für konkrete Kernservices
├── src/Http/{Controllers,Middleware,Requests,Resources}/
├── src/{Jobs,Models,Policies,Services,Support}/
└── tests/{Architecture,Contract,Feature,Security,Unit}/
```

### Erlaubte Änderungen ausserhalb des Moduls

```text
app/Domains/*/Actions|Queries|Contracts/ # nur fehlende generische Fach-Schnittstellen
app/Domains/Api/Contracts|Services/      # registrierbare API-Extension-Points
app/Providers/PluginServiceProvider.php  # nur generische Kompatibilitäts-/Boot-Härtung
tests/Feature/Plugins/                   # Kern garantiert den Plugin-Lebenszyklus
```

Änderungen an `routes/api.php` und vorhandenen Kern-API-Controllern/-Resources sind für neue Modulendpunkte grundsätzlich nicht vorgesehen. Die Journal-Korrektur ist eine gemeinsam von Web und API verwendete Accounting-Fähigkeit: Ihre Domain-Actions, fachliche Migration, Policy-Erweiterung, Webroute und Anpassung der bestehenden Journal-Vue-Seite werden als eigenständige generische Core-Seams in Track A umgesetzt. Falls eine andere bestehende Webansicht API-Akteure noch nicht korrekt darstellen kann, erfolgt die Korrektur ereignisgetrieben und ebenfalls als separater generischer Kernbeitrag.

### Abhängigkeitsregel

```text
Gäld-Kern-Fachdomains  <-  Core-Seams  <-  Accountant-API CoreBridge  <-  Modul-HTTP/Jobs
```

Der Kern hat nie eine Abhängigkeit zum Modul. Der `CoreBridge` kapselt Versionsunterschiede und ist der einzige Teil, der bei einem Upstream-API-Wechsel angepasst werden soll. Modulinterne Models speichern nur Modul-Infrastruktur; fachliche Ressourcen referenzieren die Kern-UUIDs.

### Liefer- und Git-Strategie

- **Track A – Core-Seams**: kleine, generische Commits ohne Modul-Namespace; jeder Commit besitzt Kerntests und kann separat upstream eingereicht oder beim Rebase leicht bewertet werden.
- **Track B – Accountant-API-Modul**: sämtliche Feature-Commits bleiben unter `plugins/accountant-api` und können als eigener Branch oder eigenes Repository gepflegt werden.
- Kein Commit mischt Track A und Track B. Eine Etappe referenziert explizit, welche Core-Seam-Version sie voraussetzt.
- Vor Aufnahme einer neuen Core-Änderung wird geprüft, ob ein bestehendes öffentliches Action-/Query-/Event-Interface genügt.
- Das Modul bleibt zunächst als In-Tree-Plugin integrierbar; ein separates Repository oder Distributionspaket darf dieselbe Verzeichnisstruktur liefern, ohne den Kern-Build anzupassen.

## Constitution Check

- [x] Owning Domains und Buchhaltungsinvarianten sind explizit.
- [x] Bestehende Actions, Services, DTOs, Requests, Policies und Komponenten wurden zuerst geprüft.
- [x] Organisation Scope ist für Eloquent, Raw Queries, Jobs und Validierung festgelegt.
- [x] Authentisierung, Autorisierung, Validierung, sensible Daten und Fehlerverhalten sind spezifiziert.
- [x] Die neue Top-Level-Struktur verwendet das bereits vorhandene `plugins/`-System statt einer parallelen Modul-Infrastruktur.
- [x] Kern und Modul besitzen eine gerichtete Abhängigkeit ohne Rückverweis aus dem Kern.
- [x] Modul-spezifische und generische Upstream-Änderungen werden in getrennten Lieferströmen geführt.
- [x] Test-Gates umfassen Happy Path, Fehler, Zustandsregeln und Tenant-Isolation.
- [x] Migration, Rollout, Kompatibilität und Rollback sind je Etappe berücksichtigt.

## Etappen und Abnahmetore

Die Zeitangaben gelten für eine erfahrene Person inklusive fokussierter Tests, Dokumentation und Review. Die nächste Etappe beginnt erst, wenn das jeweilige Gate erfüllt ist.

Die zehn Arbeitspakete werden aus historischen Gründen als Etappen 0–9 nummeriert; Etappe 0 ist das zwingende Fundament und keine optionale Vorphase.

### Etappe 0 – Modul-, Sicherheits- und Vertragsfundament (5–8 Tage)

**Lieferumfang**:

- Modulgerüst `plugins/accountant-api` mit Manifest, Service Provider, Konfiguration, Routen-, Migrations-, Übersetzungs- und Teststruktur erstellen.
- Einen allgemeinen, nicht an EE gebundenen Core-Extension-Vertrag samt Versionskennung definieren. Die heutige `compatibility`-Prüfung erwartet `contract_version` und `ee_version` und genügt für ein unabhängiges Community-/Accountant-Modul noch nicht.
- Exakte Vertrags-Kompatibilitätsregel definieren und beim Modul-Boot fail-closed prüfen; benötigte Erweiterung des `PluginServiceProvider` als separaten generischen Core-Seam liefern.
- Deployment-spezifische Einzelaktivierung beziehungsweise Allow-List für Plugins vorsehen, damit `PLUGINS_ENABLED=true` nicht unbeabsichtigt jedes vorhandene Plugin aktiviert.
- Kern-API und Modulrouten gemeinsam inventarisieren; Boot-Guard und automatischer Test verhindern doppelte Methode-/URI-Kombinationen und doppelte Routennamen.
- Architekturtest erzwingt: kein Kernimport ausserhalb `CoreBridge/`, keine Referenz auf `Plugins\AccountantApi` im Kern und keine Modulmigration auf fachliche Kerntabellen ohne explizite Ausnahmeentscheidung.
- Ability- und Webhook-Kataloge als registrierbare Core-Seams ausprägen, statt `TokenPermissionMap` oder Enums für jede Modulfunktion direkt zu ändern.
- Zielrollen und feingranulare Ability-Matrix für Buchhalter, Owner und technische Organisationstokens festlegen.
- Tokenprüfung so gestalten, dass Token-Scope, Benutzerrecht, Organisation und Objektzustand als Schnittmenge gelten; Wildcard-Tokens dürfen Domain-Invarianten nicht umgehen.
- Tenant-sichere verschachtelte Bindings/Resolver für indirekt zugeordnete Modelle wie Banktransaktionen und Matches. — **Bewusst zurückgestellt** (T014); erst mit dem ersten echten Konsumenten in Etappe 3 zu bauen.
- Einheitliche Response-, Fehler-, Pagination-, Filter-, Geld-, Datums- und UUID-Konventionen definieren.
- Idempotenz gegen Prozessabbruch nach Domain-Commit härten und für alle finanziellen Mutationen verpflichtend machen.
- Optimistische Parallelitätskontrolle für gleichzeitige API-/UI-Bearbeitung und `409`/`412`-Semantik definieren. — **Bewusst zurückgestellt** (T017); heute implementiert kein einziger `/api/v1`-Schreibpfad dies, und nur die zwei Korrektur-Endpunkte anders zu machen hätte den Vertrag uneinheitlich gemacht. Vor Etappe 3 zu entscheiden.
- Request-Kontext für Akteur, Token, Quelle, Correlation- und Idempotency-Key im Activity-Log.
- Persistentes Modul-Auftragsmodell und zuverlässige After-Commit-Webhooks vorbereiten; Modulmigrationen erhalten ein eindeutiges Präfix und keine fachlichen Schattenkopien.
- Modul-eigenes Vertragsdokument definieren und reproduzierbar mit dem bestehenden `api-contract.json` zu einem kollisionsfreien Gesamtvertrag prüfen.
- Boot-/Smoke-Matrix für Modul fehlend, deaktiviert, aktiviert-kompatibel und aktiviert-inkompatibel einrichten.

**Gate**: Der Kern bootet ohne Modul; das kompatible Modul bootet und registriert ausschliesslich kollisionsfreie Routen; inkompatible Versionen scheitern mit klarer Diagnose. Architekturtests beweisen die Abhängigkeitsrichtung. Negative Tests beweisen Scope-, Rollen- und Tenant-Trennung für persönliche und Organisations-Tokens; Crash/Retry erzeugt keine Duplikate; Konflikte sind stabil; jede Mutation besitzt Policy, Request, Domain-Action und Auditnachweis.

### Etappe 1 – Read-Modell und Stammdaten (5–8 Tage)

**Lieferumfang**:

- Alle neuen Endpunkte, Resources und Tests dieser Etappe im Modul umsetzen; notwendige Kern-Queries zuerst als separaten Track-A-Beitrag bereitstellen.
- Organisation/Buchhaltungsparameter read-only.
- Geschäftsjahre und Periodenstatus lesen; anschliessend kontrolliertes CRUD.
- Kontenplan um Create, Update, Deactivate/Delete sowie Import/Export ergänzen.
- MWST-Sätze, Ausgabenkategorien und relevante Metadata-Endpunkte.
- Einheitliche Filter, Sortierung, Pagination und `updated_since` für Synchronisation.

**Gate**: Ein API-Client kann alle IDs und Konfigurationswerte selbst entdecken und einen neuen Buchungsmandanten vorbereiten, ohne Werte aus der Weboberfläche abzuschreiben.

### Etappe 2 – Tagesbuchhaltung vervollständigen (14–20 Tage)

**Lieferumfang**:

- Neue Lifecycle-Endpunkte im Modul registrieren; vorhandene Kernendpunkte werden weder überschrieben noch kopiert.
- Journalentwurf über eine gemeinsame, MWST-sichere Domain-Action aktualisieren; Konto-, Kostenstellen- und MWST-Filter; CSV-Export.
- Geführte Journal-Korrektur als Core-Fähigkeit: Original unverändert lassen, Gegenbuchungs- und Ersatzentwurf als einen Korrekturvorgang anlegen, nur gemeinsam verbuchen oder gemeinsam verwerfen.
- Korrektur-Endpunkte von Beginn an im Accountant-API-Modul bereitstellen; API-Antworten zeigen Status und UUIDs von Original, Gegenbuchung und Ersatzbuchung.
- Rechnungsworkflow um Anhänge/Justificatifs, Revert-to-Draft, Duplicate, Katalogartikel und wiederkehrende Rechnungen ergänzen.
- Ausgabenworkflow um Beleg-Upload/-Download/-Löschung, OCR-Auftrag/-Status, Unapprove/Korrektur und wiederkehrende Ausgaben ergänzen.
- Jede Belegantwort enthält fachlichen Status und verknüpfte Journal-UUID.

**Gate**: Ein repräsentativer Debitoren- und Kreditorenfall kann vom Stammdatensatz und Originalbeleg bis zur Buchung, Zahlung und regelkonformen Korrektur ausschliesslich per API bearbeitet und im Web geprüft werden.

### Fokusdesign – Geführte Journal-Korrektur in Web und API

#### Fachlicher Ablauf

1. Nur eine verbuchte, nicht bereits stornierte oder korrigierte Journalbuchung kann Ausgangspunkt sein. Das Original wird nie verändert.
2. `Korrektur vorbereiten` erzeugt in einer Transaktion einen Korrekturdatensatz, einen Gegenbuchungsentwurf und einen mit allen unterstützten Kopf-, Zeilen- und MWST-Feldern kopierten Ersatzentwurf. Bis zur Finalisierung haben beide Entwürfe keine Ledgerwirkung.
3. Nur der Ersatzentwurf ist fachlich editierbar. Gegenbuchungszeilen werden aus dem Original abgeleitet und bleiben gesperrt.
4. `Korrektur verbuchen` sperrt Korrektur, Original und beide Entwürfe mit `lockForUpdate`, validiert den vollständigen Vorgang und verbucht Gegenbuchung und Ersatz in einer äusseren Datenbanktransaktion. Schlägt ein Teil fehl, bleibt der gesamte Vorgang als Entwurf bestehen.
5. `Korrektur verwerfen` löscht beide unverbuchten Entwürfe und den offenen Korrekturdatensatz atomar; das Audit-Ereignis bleibt erhalten. Nach der Verbuchung ist ein Verwerfen ausgeschlossen.
6. Eine später nötige weitere Korrektur beginnt bei der zuletzt verbuchten Ersatzbuchung. Das bereits korrigierte Original darf kein zweites Mal korrigiert werden.

#### Domain-Ownership und Datenmodell

- Die Accounting-Domain besitzt `JournalCorrection`, Statusübergänge und die fachlichen Actions `PrepareJournalCorrectionAction`, `PostJournalCorrectionAction` und `CancelJournalCorrectionAction`. Web und API rufen ausschliesslich diese Actions auf; Controller orchestrieren keine Buchungszeilen.
- Eine additive Kerntabelle `journal_corrections` speichert UUID, `organization_id`, eindeutige UUIDs für Original, Gegenbuchung und Ersatzbuchung, Status (`draft`, `posted`), Begründung, Quelle (`web`, `api`), optionalen Benutzer-/Tokenkontext, Client-Operations-ID/Request-Hash, `posted_at` und Timestamps. Alle drei Journal-Fremdschlüssel sind indiziert; die Beziehungen werden nach Materialisierung eindeutig und mit `restrictOnDelete` geschützt. Organisationsgleichheit wird in den Actions geprüft und zusätzlich über zusammengesetzte Fremdschlüssel `(organization_id, journal_entry_id)` abgesichert.
- Neue Gegenbuchungen erhalten zusätzlich ein nullable, eindeutiges und indiziertes `reversal_of_entry_id`. Sowohl das bestehende reine Storno als auch die geführte Korrektur schreiben diese strukturierte Beziehung; das Präfix `REV-` bleibt nur eine lesbare Referenz und ist nicht länger die fachliche Identität.
- Das Original erhält kein Korrekturfeld und wird auch bei archivierten Buchungen nicht aktualisiert. Beziehungen werden über `journal_corrections` gelesen. Dadurch bleibt die Unveränderlichkeit des Hauptbuchs erhalten.
- Bestehende historische Stornos bleiben gültig. Für neue Vorgänge ist die UUID-Verknüpfung massgeblich, nicht das Präfix `REV-`. Vor Freigabe wird entschieden, ob eindeutige Altdaten aus `journal_events.payload` einmalig nachgeführt oder nur als Legacy-Storno angezeigt werden; aus Referenztext allein wird keine Beziehung geraten.
- Eine gemeinsame `UpdateJournalDraftAction` ersetzt die zeilenweise Aktualisierung im Webcontroller. Sie validiert Saldo, Organisation, Konten und sämtliche aktuell unterstützten MWST-Felder und verhindert, dass Gegenbuchungsentwürfe separat verändert, gelöscht oder verbucht werden.

#### Zulässigkeit und Perioden

- Die Korrektur ist zunächst für manuelle, API- und Migrationsbuchungen vorgesehen. Buchungen, die mit Rechnung, Ausgabe, Bankabstimmung, Lohn, Anlage, MWST-Abschluss oder Jahresabschluss verbunden sind, werden mit `source_managed_entry` abgelehnt und weiterhin über die Action ihres Ursprungsmoduls korrigiert.
- Da `journal_entries.type` die Herkunft heute nicht zuverlässig abbildet, ermittelt ein zentraler Eligibility-Service die bekannten Quellbeziehungen. Eine spätere explizite `source_type`/`source_id`-Normalisierung ist ein eigener Core-Seam und keine Voraussetzung für die erste Auslieferung, sofern alle bestehenden Quellen vollständig geprüft werden.
- Das Korrekturdatum ist explizit. Web und API schlagen das Originaldatum vor, wenn Geschäftsjahr und MWST-Periode offen sind, andernfalls das aktuelle offene Datum; eine stillschweigende Datumsverschiebung findet nicht statt.
- Vor dem gemeinsamen Verbuchen werden Geschäftsjahr, MWST-Periode, Konten, Referenzen, Soll/Haben-Ausgleich und Organisationszugehörigkeit für beide Einträge geprüft. Eine gesperrte Originalperiode verhindert eine Gegenbuchung in einer späteren offenen Periode nicht, muss aber im Ergebnis sichtbar sein.

#### API-Vertrag

```text
POST   /api/v1/journal-entries/{original}/corrections
GET    /api/v1/journal-corrections/{correction}
PUT    /api/v1/journal-corrections/{correction}/replacement
POST   /api/v1/journal-corrections/{correction}/post
DELETE /api/v1/journal-corrections/{correction}
```

- `POST` verlangt `reason`, `correction_date` und optional eine vollständige Ersatzbuchung im bestehenden expliziten oder MWST-Kurzformat. Ohne Ersatzpayload wird das Original vollständig kopiert. Antwort: `201` mit einer `JournalCorrectionResource`.
- `PUT replacement` ändert ausschliesslich den Ersatzentwurf und verwendet dieselben extrahierten Validierungsregeln und Mapper wie `POST /journal-entries`; Antwort: `200`.
- `POST .../post` verbucht beide Entwürfe atomar; Antwort: `200` mit Original, Gegenbuchung, Ersatzbuchung, Status und nicht rekursiven Beziehungslinks.
- `DELETE` ist nur im Status `draft` erlaubt und antwortet mit `204`.
- Alle Mutationen verlangen `Idempotency-Key`. Der Korrekturvorgang beziehungsweise die Client-Operations-ID wird in derselben Transaktion wie die fachliche Mutation gespeichert, damit ein Prozessabbruch nach dem Commit bei Wiederholung das bereits erzeugte Ergebnis liefert.
- Neue Policy-Ability `correct` verlangt `AccountingEdit`; Token-Scope, Benutzerrecht, Organisation und Objektstatus gelten gemeinsam. Organisationsfremde UUIDs liefern `404` ohne Existenzhinweis.
- Stabile Konfliktcodes: `journal_entry_already_corrected`, `journal_entry_already_reversed`, `journal_correction_state_conflict`, `journal_correction_concurrent_transition`, `source_managed_entry` und `idempotency_conflict`. Validierungs-, Geschäfts-/MWST-Perioden- und Saldofehler behalten ihre bestehenden Fehlerfamilien.
- Ein neues Ereignis/Webhook `journal_entry.corrected` wird erst nach erfolgreichem Commit veröffentlicht und enthält Ereignis-ID sowie die drei Journal-UUIDs; Entwurfserstellung und Verwerfen werden mindestens im Audit-Log dokumentiert.

#### Weboberfläche

- Bei zulässigen verbuchten Journalbuchungen ersetzt die Aktion `Korrigieren` den technisch geprägten Einzel-Stornoablauf; `Stornieren` kann für bewusst reine Gegenbuchungen separat bestehen bleiben.
- Der Dialog zeigt Original, nicht editierbare Gegenbuchung und editierbare Ersatzbuchung nebeneinander beziehungsweise auf kleinen Bildschirmen schrittweise. Begründung und Korrekturdatum sind Pflichtfelder.
- Nach dem Vorbereiten landet der Benutzer direkt im Ersatzentwurf. Ein deutlicher Hinweis erklärt, dass erst `Korrektur verbuchen` beide Buchungen wirksam macht.
- Journalzeilen und Detailansicht kennzeichnen `korrigiert`, `Gegenbuchung` und `Ersatzbuchung` und verlinken den gesamten Vorgang. API-erzeugte Korrekturen erscheinen nach Reload identisch.

#### Abnahme und Aufwand

- Kern-Tests beweisen unverändertes Original, vollständige Feld-/MWST-Kopie, ausgeglichene Gegenbuchung, gemeinsame Ledgerwirkung, Rollback bei Fehler im zweiten Eintrag und Sperre der Einzelaktionen.
- API-Tests decken Prepare/Get/Update/Post/Delete, explizite und MWST-Kurzform, Idempotenz-Replay und Payload-Konflikt, Cross-Tenant-Zugriff, Rechte, Parallelaufrufe, Null-/Lang-/Doppelreferenzen sowie stabile Fehlercodes ab.
- Periodentests decken offenes und geschlossenes Geschäftsjahr, gesperrte MWST-Periode und Korrektur in einer späteren offenen Periode ab. Quellmodultests beweisen die Ablehnung source-verwalteter Buchungen.
- Frontendtests beziehungsweise ein gezielter Browser-Smoke prüfen den vollständigen Dialog, Abbruch, Fehleranzeige und die sichtbare Verknüpfung nach dem API-Aufruf.
- Geschätzter Aufwand für dieses Fokuspaket: 7–10 Arbeitstage inklusive Migration, Core-Actions, Web-UX, Modul-API, Tests und Vertragsdokumentation. Das Modul-/Sicherheitsfundament aus Etappe 0 ist Voraussetzung und nicht darin enthalten.

### Etappe 3 – Banking, Zahlungsverkehr und Abstimmung (8–12 Tage)

**Voraussetzung (Track A)**: Reconciliation-Transaktionen und Matching aus dem umfangreichen Webcontroller in wiederverwendbare, API-unabhängige Domain-Actions extrahieren. Web und Modul konsumieren danach denselben Kernpfad.

**Lieferumfang**:

- Bankkonto Update/Deactivate sowie Banktransaktionen Liste/Detail/manuelle Erfassung.
- CAMT-Import mit Importstatus und Dublettennachweis.
- Offene Transaktionen und Match-Vorschläge.
- Zuordnung zu Rechnung, Ausgabe, MWST, Privat oder manueller Buchung.
- Match bestätigen, Abstimmung aufheben sowie optional Bulk-/Auto-Reconciliation.
- Offene Kreditorenzahlungen und pain.001-Erzeugung/Download; Approval-Voraussetzung explizit erzwingen.

**Gate**: Ein Monatskontoauszug kann ohne Webeingabe vollständig importiert und auf null offene Transaktionen abgestimmt werden; jede Zuordnung und Gegenbuchung ist in UI und Audit-Log sichtbar.

### Etappe 4 – Reports und asynchrone Exporte (5–8 Tage)

**Lieferumfang**:

- Erfolgsrechnung, Bilanz, MWST-Vorschau, Saldobilanz, Cashflow und Debitoren-/Kreditoren-Aging als JSON.
- Journal und Kontenblätter mit Drill-down sowie CSV/PDF-Ausgaben.
- Persistenter Exportauftrag mit Start-, Status-, Fehler- und gesichertem Download-Endpunkt.
- Bulk-Aggregation beziehungsweise Query-Budget für Reports; Chunking/Streaming für grosse Journale.

**Gate**: Alle Kernberichte stimmen für dieselbe Periode mit Webansicht und Ledger überein; ein grosser Export blockiert keinen HTTP-Worker und bleibt nach Queue-Retry eindeutig.

### Etappe 5 – MWST und Periodenabschluss (5–8 Tage)

**Lieferumfang**:

- MWST frisch berechnen, validieren, verbuchen und mit Zahlung abstimmen.
- Eine verbindliche Korrekturregel durch versionierte Gegenbuchung statt veränderter Altabschlüsse.
- Periodensperren und Abgrenzungsfehler als stabile API-Konflikte.
- Eröffnungssalden und historische Salden.

**Gate**: MWST-Abschluss und Korrektur sind idempotent, concurrent-sicher, vollständig auditiert und mit Report sowie Journal abgestimmt.

### Etappe 6 – Anlagen, Jahresabschluss und Archiv (8–12 Tage)

**Voraussetzung (Track A)**: Der atomare Datenbankteil des Jahresabschlusses wird vollständig in die bestehende `YearEndClosingAction` verschoben; Modul und Web verwenden denselben Kernpfad.

**Lieferumfang**:

- Anlagen CRUD, Abschreibung, Ausbuchung/Verkauf und Abschreibungsplan.
- Abschluss-Preflight für offene Entwürfe, Abstimmungen, MWST, Lohn und Abschreibungen.
- Asynchrone Orchestrierung: Preflight, akzeptierter Auftrag, atomare Abschlussbuchung/Sperre/Folgeeröffnung, danach resumierbare versionierte Archiverzeugung.
- Separat berechtigte Wiedereröffnung und dokumentiertes Restatement-Verhalten.
- Legal Archive Liste, Erzeugung, Hash-Verifikation und sicherer Jahresbundle-Download.

**Gate**: Ein vollständiges Testjahr kann vorbereitet, geschlossen, archiviert und kontrolliert wiedereröffnet werden; injizierte Fehler hinterlassen keinen Teilabschluss und Archiv-Retries erzeugen keine widersprüchlichen Versionen.

### Etappe 7 – Payroll und Sozialabgaben (10–15 Tage)

**Lieferumfang**:

- Zuerst read-only Employee und Salary Slips mit gesonderten PII-Abilities und strikt begrenzten Feldern.
- Danach Employee CRUD, Lohnlauf Preview/Generate, einzelne Anpassungen und idempotente Periodeneindeutigkeit.
- Lohnabrechnung Post/Unpost, PDF und Lohnausweis.
- Sozialabgaben und Quellensteuer berechnen und buchen.
- E-Mail-Versand als expliziter, dokumentierter Seiteneffekt oder separater Befehl.

**Gate**: Ein Lohnmonat kann berechnet, kontrolliert, gebucht, dokumentiert und korrigiert werden; unberechtigte Rollen erhalten weder sensible Felder noch indirekte Rückschlüsse.

### Etappe 8 – Optionale Fachmodule (je Modul separat, 2–4 Wochen insgesamt)

**Lieferumfang nach tatsächlicher Nutzung priorisieren**:

- Budgets.
- Kostenstellen und analytischer Bericht.
- Fremdwährungskurse und kontrollierter ECB-Abruf.
- Steuerdeklarationen.
- Konsolidierung und Eliminierungen.
- Erweiterte Migration, Auto-Reconciliation und Bank-Sync.

**Gate**: Jedes Modul besitzt ein eigenes Feature-Gate, eigene Abilities, Tenant-/Status-Tests und kann unabhängig ausgeliefert oder deaktiviert werden.

### Etappe 9 – Pilot, Härtung und Upstream-Kompatibilität (5–8 Tage plus Pilotlauf)

**Lieferumfang**:

- Rollout in Stufen: read-only Canary, Entwürfe, Buchen/Stornieren, Bank, Reports, MWST, Jahresabschluss und zuletzt Payroll.
- Ein Testmandant führt mindestens einen Monatszyklus parallel zu einem kontrollierten Referenzablauf durch.
- Abstimmung von API, UI, Reports, Audit-Log und Exporten.
- Lasttest mit repräsentativem Journal, Queue-Ausfall, Retry und abgelaufenem Download.
- Breaking-Change- und Deprecation-Regeln dokumentieren.
- Installation, Update, Deaktivierung, Backup und Datenaufbewahrung des Moduls dokumentieren und als Restore-Probe testen.
- Modul gegen den aktuellen Upstream-Integrationsbranch (derzeit `upstream/develop`), `upstream/main` und den vorgesehenen Release-Tag in separaten Kompatibilitätsjobs testen; Abweichungen ausschliesslich im `CoreBridge` oder durch einen generischen Track-A-Seam beheben.
- Alle Track-A-Änderungen als kleine Upstream-PRs bereitstellen; modul- und kundenspezifische Logik bleibt vollständig in Track B.
- Einen Probe-Update durchführen: produktionsnaher Datenbankstand, neue Kernversion, Modulmigrationen, Contract-Smokes und dokumentierter Rollback/Kill Switch.

**Gate**: Keine ungeklärte Hochrisikoabweichung, alle Release- und Kompatibilitätschecks grün, keine Routenkollision, dokumentierter Rollback pro Modulmigration, erfolgreicher Probe-Update und mindestens ein erfolgreicher Pilotmonat.

## Zeit- und Release-Orientierung

| Zielstand | Enthaltene Etappen | Kumulativ, eine erfahrene Person |
|---|---|---:|
| Modul-Fundament und Stammdaten | 0–1 | 2–3 Wochen |
| Vollständige Tagesbuchhaltung | 0–2 | 5–7 Wochen |
| Operativer Monatszyklus inkl. Bank und Reports | 0–4 | 8–10 Wochen |
| Vollständige Kernbuchhaltung inkl. Abschluss/Archiv | 0–6 | 11–14 Wochen |
| Inklusive Payroll | 0–7 | 14–18 Wochen |
| Vollständige optionale Modulparität | 0–9 | 17–22 Wochen |

Die Schätzung schliesst Review, fokussierte Tests und Dokumentation ein, jedoch keine externe Bank- oder Behördenzertifizierung. Payroll und optionale Module können nach Etappe 2 teilweise parallelisiert werden.

## Data and Contract Changes

### Data Model

- Bestehende Buchhaltungsmodelle bleiben Quelle der Wahrheit; keine API-Kopien.
- Die generische Accounting-Kerntabelle `journal_corrections` hält den Zustand und die dauerhafte Verknüpfung einer Korrektur. `journal_entries.reversal_of_entry_id` ersetzt für neue Gegenbuchungen die bisher nur namensbasierte `REV-`-Zuordnung. Diese fachlichen Migrationen gehören zu Track A und funktionieren unabhängig vom Accountant-API-Modul.
- Ein persistenter modul-eigener `AccountantApiOperation`-Datensatz ist für asynchrone Aufträge vorzusehen: UUID, Organisation, Akteur/Token, Typ, Status, Fortschritt, idempotente Referenz, Eingabe-Hash, Ergebnisdatei, Fehlercode, Ablaufzeitpunkte und Timestamps.
- Bestehende `ApiIdempotencyKey`-Daten werden weiterverwendet und für Crash-after-Commit-Sicherheit weiterentwickelt; Aufbewahrung und Cleanup werden vor Etappe 2 festgelegt.
- Falls optimistische Parallelitätskontrolle nicht verlässlich über `updated_at` möglich ist, wird eine monotone Version als generische Kernmigration in Track A ergänzt; sie muss auch ohne Modul fachlich sinnvoll und rückrollbar sein.
- Modulmigrationen liegen unter `plugins/accountant-api/migrations`, verwenden eindeutig präfixierte Tabellennamen und verändern keine fachlichen Kerntabellen. Eine aus fachlichen Gründen nötige Kernmigration gehört zu Track A und muss ohne Modul sinnvoll sein.

### HTTP/API Contract

**Gemeinsame Regeln**:

- Erfolgsantworten verwenden `data`; Listen ergänzen `links` und `meta`.
- Fehler liefern mindestens `message`, stabilen `code` und bei Validierung `errors`.
- Mutationen verlangen `Idempotency-Key`; konkurrierende Versionen oder Schlüsselkonflikte liefern `409` beziehungsweise `412`.
- Erzeugung liefert `201`, synchrone Befehle `200`, Löschung ohne Inhalt `204`, asynchrone Annahme `202`.
- Datei-Downloads prüfen bei jedem Abruf Organisation, Recht, Ablaufzeit und erlaubten Dateinamen.
- Webhooks liefern stabile Event-IDs mindestens einmal; Consumer müssen Ereignisse deduplizieren können.

**Routengruppen nach Etappe**:

```text
/api/v1/organization, /fiscal-years, /accounts, /vat-rates, /expense-categories
/api/v1/contacts, /invoices, /expenses, /journal-entries
/api/v1/journal-corrections
/api/v1/bank-accounts/{bankAccount}/transactions, /reconciliations, /payment-runs
/api/v1/reports/*, /exports, /operations/{operation}
/api/v1/opening-balances, /vat-settlements, /fixed-assets, /year-end-closings, /archives
/api/v1/employees, /payroll-runs, /salary-slips, /salary-certificates
```

Konkrete Request-/Response-Schemas werden pro Etappe zuerst im Modulvertrag `plugins/accountant-api/contract/api-contract.json` ergänzt. Ein Contract-Test prüft diesen gegen die bestehende `contract/api-contract.json` auf Pfad-, Operations-ID-, Schema- und Fehlercode-Kollisionen und erzeugt beziehungsweise validiert die veröffentlichte Gesamtsicht. Geschäftsjahrbezogene Operationen verwenden `fiscal_year_id`, damit abweichende oder verlängerte Geschäftsjahre eindeutig bleiben.

### Frontend States

- Bestehende Seiten müssen API-erzeugte Zustände nach Reload korrekt darstellen.
- Die Journalansicht führt manuelle/API-/Migrationsbuchungen über einen Korrekturdialog; Original, Gegenbuchung und Ersatz werden als zusammengehöriger Vorgang dargestellt und verlinkt.
- Verknüpfte Korrekturentwürfe können in der UI weder einzeln verbucht noch einzeln gelöscht werden.
- Activity-Log zeigt Akteur, Tokenname, Quelle, Aktion und Ergebnis.
- Dashboard- und Report-Caches werden nach API-Mutationen invalidiert.
- Echtzeit-Push ist nicht Bestandteil der ersten Etappen; optionales Polling kann im Pilot ergänzt werden.

## Test Strategy

- **Feature/integration**:
  - neue Featuretests ausschliesslich unter `plugins/accountant-api/tests/Feature/`; bestehende Kerntests nur für separate Core-Seams erweitern
  - fokussierte Dateien wie `AccountantReferenceDataApiTest.php`, `AccountantDocumentFlowApiTest.php`, `ReconciliationApiTest.php`, `ReportingApiTest.php`, `ClosingApiTest.php` und `PayrollApiTest.php`
  - jede API-Mutation gegen den entsprechenden Web-/Service-Zustand und Reportcache prüfen
- **Security**:
  - Modultests unter `plugins/accountant-api/tests/Security/` für Ability × Rolle × Tokenart × Organisation
  - Cross-Tenant-Routenbindung, fremde IDs in Payloads, sensitive Payroll-Felder, Downloadpfade und abgelaufene Links
  - gebuchte/geschlossene/archivierte Unveränderlichkeit sowie Owner-only-Ausnahmeaktionen
- **Unit/domain**:
  - vorhandene Domain-Tests weiterverwenden; neue Unit-Tests nur für extrahierte Actions und echte Invarianten
  - Idempotency Replay/Conflict/Crash, Abschluss-Preflight, Reconciliation- und Exportstatus-Transitionen
  - Journal-Korrektur als Aggregat: Feldtreue einschliesslich MWST, Zustandsübergänge, Einzelaktionssperren, atomare Doppelbuchung, Quellmodul-Ausschluss und strukturierte Reversal-Beziehung
- **Contract/documentation**:
  - Modulvertrag, Gesamt-`ApiContractTest`, CE-Edition-Boundary und Scribe/OpenAPI-Generierung
  - Response-Felder und Fehlercodes als kompatibler Vertrag
- **Architecture/compatibility**:
  - Kern bootet bei fehlendem und deaktiviertem Modul; das Modul bootet nur innerhalb seiner deklarierten Kompatibilität
  - keine Modulreferenz im Kern, keine Kernimporte ausserhalb `CoreBridge/`, keine Route-/Tabellenkollision
  - Matrix-Smokes gegen jede unterstützte Kernversion sowie gegen den vorgesehenen nächsten Upstream-Zielstand
- **Frontend/build**:
  - gezielte Tests für Korrekturdialog und Aktionssperren; Frontend-Build als Gate
  - weitere Frontendtests nur, wenn Activity-Log oder Polling angepasst wird
- **Manual/browser**:
  - pro Releaseziel ein API-Ende-zu-Ende-Ablauf und anschliessende Sichtprüfung in Journal, Beleg, Bankabstimmung, Dashboard, Report und Activity-Log

Alle Verifikationen laufen durch Sail. Pro Etappe zuerst gezielte Tests, danach PHPUnit-Gesamtsuite, Pint, PHPStan und gegebenenfalls Frontend-Build.

## Rollout and Operations

- **Installation und Migration**: In Etappe 2 kommen additive fachliche Kernmigrationen für `journal_corrections` und die strukturierte Reversal-Beziehung hinzu. Vorhandene Buchungen bleiben unverändert; ein Legacy-Backfill wird nur für eindeutig über `journal_events.payload` verknüpfbare Stornos ausgeführt. Der modul-eigene Auftragsstatus erhält eine additive Migration. Modulmigrationen werden nur bei installiertem Modul ausgeführt, einzeln versioniert und vor jedem Kernupdate gesichert.
- **Feature flag**: `PLUGINS_ENABLED`, die deployment-spezifische Freigabe von `accountant-api`, die Manifest-Aktivierung und bestehendes `api_access` bilden gemeinsam das Haupt-Gate. Payroll und Advanced-Module respektieren zusätzlich ihre bestehenden Feature Flags. Riskante API-Domänen erhalten bis Pilotende modul-eigene Kill Switches.
- **Queue/scheduler/storage impact**: Exporte, OCR, Archive, Lohn-Massenläufe und externe Abrufe laufen auf dedizierten Queues mit eindeutigen Jobs, `failed()`-Behandlung, Backoff und bereinigbaren Ergebnisdateien. `retry_after` muss jedes Job-Timeout übersteigen.
- **Monitoring and rollback**: API-Fehlerrate, 409/412-Konflikte, Queue-Failures, Laufzeiten, Cross-Tenant-Denials und Exportgrössen beobachten. Routen/Abilities können etappenweise deaktiviert werden; additive Migrationen besitzen `down()` oder einen dokumentierten Forward-Fix.
- **Update/rollback**: Zuerst Kernkompatibilität im CI prüfen, danach Kern deployen, Modul aktivieren und Modulmigrationen ausführen. Bei einem Modulfehler werden dessen Routen per Kill Switch deaktiviert; fachliche Kerndaten bleiben verfügbar. Modul-Infrastrukturdaten werden beim Deaktivieren nicht automatisch gelöscht.
- **Documentation/changelog**: Pro Etappe Modul-OpenAPI, Gesamtvertrag, Modul-README und Changelog aktualisieren. Core-Seams erhalten separate Kerndokumentation. Externe Grenzen wie Bankfreigabe und Behördeneinreichung explizit dokumentieren.

## Post-Design Constitution Check

- [x] API bleibt Adapter; Fachlogik wird nicht dupliziert.
- [x] Journal-Korrekturen verändern das Original nie und werden als atomarer Accounting-Kernvorgang von Web und API gemeinsam genutzt.
- [x] Gegenbuchungs- und Ersatzentwurf können weder am Domain-Service noch an der HTTP-Grenze einzeln wirksam werden.
- [x] Sicherheitsfundament ist eine harte Voraussetzung vor neuen Finanzmutationen.
- [x] Jede Etappe besitzt unabhängige Akzeptanz- und Rollback-Gates.
- [x] Persistente neue Daten sind auf fachlich notwendige Korrekturbeziehungen und nachverfolgbare asynchrone Aufträge begrenzt.
- [x] CE/EE- und Feature-Flag-Grenzen bleiben erhalten.
- [x] Der Plan ist mit additiven, kleinen Upstream-Beiträgen vereinbar.
- [x] Sämtlicher neuer Featurecode besitzt mit `plugins/accountant-api` einen eigenen Lebenszyklus.
- [x] Vorhandene Kernrouten werden nicht verschoben oder überschrieben.
- [x] Kompatibilität, Modul-Ausfall und Upstream-Update sind eigene automatisierte Gates.

## Complexity Tracking

| Deviation | Why it is needed | Simpler alternative rejected because |
|---|---|---|
| Persistenter generischer API-Auftragsstatus | API-Clients benötigen Status, Fehler und sicheren Download ohne Websession oder E-Mail | Nur Queue-ID, Cache oder E-Mail ist nicht dauerhaft, nicht sauber autorisierbar und nicht retry-sicher genug |
| Vorbereitende Action-Extraktion für Reconciliation und Jahresabschluss | Web und API müssen exakt dieselben Transaktionen und Invarianten verwenden | Aufruf von Webcontrollern oder duplizierte API-Logik erzeugt Drift und schwer testbare Seiteneffekte |
| Eigenständiges Accountant-API-Modul im vorhandenen Plugin-System | Featurecode soll unabhängig vom Kern aktualisierbar, deaktivierbar und bei Upstream-Merges konfliktarm sein | Direkte Erweiterung von `app/Domains/Api`, `routes/api.php` und Kernmigrationen verteilt Konfliktflächen über den Hauptbranch |
| Zentraler `CoreBridge` trotz zusätzlicher Adapter | Änderungen an konsumierten Kernservices sollen an einer Stelle aufgefangen und getestet werden | Direkte Kernimporte aus jedem Controller, Job und Resource koppeln das gesamte Modul an interne Klassen |
| Generische registrierbare Ability-/Webhook-Seams im Kern | Ein Modul muss Berechtigungen und Ereignisse beitragen können, ohne statische Kernlisten pro Feature zu patchen | Direktes Ergänzen von `TokenPermissionMap` und Kern-Enums verursacht bei jedem Ausbau wiederkehrende Mergekonflikte |
| Persistentes Journal-Korrekturaggregat | Gegenbuchung und Ersatz müssen gemeinsam gesperrt, geprüft, verbucht, verworfen und dauerhaft verknüpft werden | Zwei lose Journalentwürfe könnten einzeln verbucht werden und eine fachlich unvollständige Korrektur hinterlassen |
