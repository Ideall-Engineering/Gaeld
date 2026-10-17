# Implementation Plan: Projekte und Zuordnung von Buchungszeilen

**Branch**: `feature/projects-and-people` | **Date**: 2026-10-17 | **Spec**: [spec.md](spec.md)

## Summary

Kostenstellen werden zu Projekten erweitert. Eine neue Tabelle `line_allocations` verbindet Buchungszeilen mit Projekten und Personen. Sie hängt an `transaction_lines` nur über einen Fremdschlüssel und wird nie über die Buchungszeile geschrieben, deshalb greifen weder die Archivsperre noch die MWST- und Abschlusssperren. Alle Stellen, die Zeilen neu anlegen, reichen die Zuordnung über `JournalLineData` weiter. Eine eigene Projektauswertung liest nur aus der neuen Tabelle.

## Technical Context

- Laravel 13, PHP 8.4, PostgreSQL 16, Inertia mit Vue 3, PHPUnit, Spatie activitylog über den Trait `Auditable`.
- Modulschaltung über `FeatureFlag::enabledForOrg`: `organizations.enabled_modules` hat Vorrang vor `config/features.php`.
- `transaction_lines.id` ist `bigint`, `employees.id` ist `uuid`, `cost_centers.id` ist `bigint`.
- `transaction_lines.cost_center_id` ist eine `string`-Spalte ohne Fremdschlüssel, nicht in `$fillable`. Gäld schreibt sie nirgends. Sie bleibt unverändert.
- Die vorhandene Analytik (`CostCenterController::analyticalReport`, Modul `analytical`) bleibt unverändert, um Konflikte mit dem Originalprojekt klein zu halten. Die Projektauswertung ist ein eigener Service.

## Constitution Check

- Buchhaltungskern unverändert: keine Änderung an Saldo-, MWST-, Abschluss- und Archivlogik. Belegt durch den Vergleichstest (T035).
- Mandantentrennung: alle neuen Modelle mit `BelongsToOrganization`; Fremdreferenzen werden gegen die Organisation geprüft.
- Additiv: nur neue Tabellen und neue Spalten mit Standardwerten; `down()` entfernt sie vollständig.
- Öffentliches Repository: keine Instanzdaten. Ein späterer Datenimport läuft über ein lokales Skript ausserhalb des Repositorys.

## Datenmodell

### `cost_centers` (neue Spalten, alle nullable oder mit Standard)

| Spalte | Typ | Bemerkung |
|---|---|---|
| `kind` | string(20), Standard `project` | `area`, `project`, `internal` (Enum `ProjectKind`) |
| `status` | string(20), Standard `in_progress` | `pipeline`, `possible`, `in_progress`, `done`, `lost` (Enum `ProjectStatus`) |
| `contact_id` | uuid nullable, FK `contacts` nullOnDelete | Kunde |
| `responsible_employee_id` | uuid nullable, FK `employees` nullOnDelete | |
| `target_date` | date nullable | |
| `expected_amount` | decimal(15,2) nullable | netto |
| `notes` | text nullable | |

Bestehende Kostenstellen erhalten `kind = project`, `status = in_progress`. Auf Produktionsdaten ist die Tabelle heute leer.

### `line_allocations` (neu)

| Spalte | Typ | Bemerkung |
|---|---|---|
| `id` | bigint | |
| `organization_id` | uuid, FK cascade | für Scope und Indizes |
| `transaction_line_id` | bigint, FK `transaction_lines` cascadeOnDelete | |
| `cost_center_id` | bigint nullable, FK `cost_centers` restrictOnDelete | |
| `employee_id` | uuid nullable, FK `employees` restrictOnDelete | |
| `share` | decimal(7,4) | Prozent, > 0 und ≤ 100 |
| `source` | string(20) | `manual`, `carried`, `bulk`, `api`, `legacy` |
| timestamps | | |

Check-Constraints: `cost_center_id IS NOT NULL OR employee_id IS NOT NULL`, `share > 0 AND share <= 100`. Index auf `(organization_id, cost_center_id)` und `(transaction_line_id)`. Die Summenregel pro Zeile prüft der Service, nicht die Datenbank.

Das Löschen einer Zeile löscht ihre Zuordnungen über die Datenbank. Archivierte Zeilen lassen sich ohnehin nicht löschen (`LocksArchivedTransactionLine`).

### `allocation_reviews` (neu)

`id`, `organization_id`, `journal_entry_id` (uuid, FK cascade), `reason` (string), `previous` (json, die nicht übernommenen Zuordnungen), `resolved_at`, `resolved_by`, timestamps. Ein offener Eintrag pro Buchung (partieller Unique-Index auf `journal_entry_id WHERE resolved_at IS NULL`).

### Einmalige Übernahme

Die Migration übernimmt jede Zeile mit gesetzter, auf eine Kostenstelle derselben Organisation auflösbarer `cost_center_id` als 100 %-Zuordnung mit `source = legacy`. Nicht auflösbare Werte werden übersprungen und gezählt ausgegeben.

## Dienste und Datenfluss

### `JournalLineData`

Neues optionales Feld `?array $allocations = null` mit `LineAllocationData[]` (`costCenterId`, `employeeId`, `share`).
- `null`: der Aufrufer macht keine Angabe (Übernahme durch Abgleich, siehe unten).
- `[]`: ausdrücklich keine Zuordnung.

`fromArray` liest `allocations` und, als Kurzform, `cost_center_id` (wird zu einer 100 %-Zuordnung).

### `LineAllocationService`

- `validate(string $orgId, array $allocations)`: Organisation, aktive Projekte und Personen, Summe ≤ 100, keine doppelten Paare.
- `writeFor(TransactionLine $line, ?array $allocations, string $source)`: ersetzt die Zuordnungen der Zeile; `null` tut nichts.
- `replaceForEntry(JournalEntry $entry, array $byLineId, string $source)`: für den Dialog, die Mehrfachzuordnung und die API. Läuft ohne jede Prüfung des Sperrzustands der Buchung.
- `carryOver(Collection $oldLines, Collection $newLines, array $incoming)`: Abgleich für Ändern ohne Angabe (siehe unten).
- `snapshot(JournalEntry $entry)`: liefert die Zuordnungen je Zeile als Array für die Versionstabelle.

Protokoll: `LineAllocation` nutzt `Auditable`; zusätzlich schreibt `replaceForEntry` einen Sammeleintrag `allocations.replaced` mit altem und neuem Stand an die Buchung, damit eine Änderung im Protokoll als ein Vorgang erscheint.

### Übernahmestellen

| Stelle | Verhalten |
|---|---|
| `LedgerService::persistEntry` | schreibt `line->allocations` nach `TransactionLine::create`. Deckt `postEntry` und `createDraft` und damit alle Fachmodule. |
| `LedgerService::postDraft` | legt keine Zeilen neu an, Zuordnungen bleiben. Nur Test. |
| `LedgerService::reverseEntry` | übernimmt die Zuordnungen der Originalzeile (gleicher Anteil; der Betrag dreht über die Zeile). |
| `UpdateJournalDraftAction` | Zeilen werden neu angelegt; Zuordnungen aus der Eingabe, sonst Abgleich. |
| `AmendPostedJournalEntryAction` | wie oben; der Versionseintrag enthält zusätzlich den Zuordnungs-Snapshot. |
| `PrepareJournalCorrectionAction` Storno | übernimmt aus dem Original. |
| `PrepareJournalCorrectionAction` Ersatz | ohne eigene Nutzlast Kopie des Originals samt Zuordnungen; mit Nutzlast aus der Eingabe, sonst Abgleich. |
| `OpeningBalanceImporter`, `RecordOpeningBalancesAction`, `GenerateOpeningBalancesAction` | keine Zuordnungen (Bilanzkonten). Nur Test, dass nichts geschrieben wird. |
| `DeletePostedJournalEntryAction` | Versionseintrag `deleted` enthält den Zuordnungs-Snapshot; die Datenbank entfernt die Zuordnungen mit den Zeilen. |

### Abgleich beim Ändern ohne Angabe

Für jede neue Zeile ohne Angabe wird genau eine alte Zeile gesucht mit gleichem Konto, gleicher Seite und gleichem Betrag. Findet sich genau eine, die noch nicht vergeben ist, werden deren Zuordnungen übernommen (`source = carried`). Sonst: gleiches Konto und gleiche Seite, wenn es auf beiden Seiten genau eine solche Zeile gibt (Betragsänderung). Bleiben danach alte Zuordnungen übrig, entsteht ein `allocation_reviews`-Eintrag mit den übrig gebliebenen Zuordnungen. Es wird nie geraten.

Die Weboberfläche sendet die Zuordnungen immer mit, dort greift der Abgleich nur als Rückfall.

## Projektauswertung

`ProjectReportService::forProject(CostCenter $project, from, to)`:
- Projekt plus alle Nachfahren (rekursive CTE über `parent_id`).
- Summe über `line_allocations` × Zeilenbetrag × Anteil, nur gebuchte Buchungen, Datum im Zeitraum, `journal_entries.type <> 'year_end_closing'`, nur Konten der Art `revenue` und `expense`.
- Rundung: pro Zeile auf Rappen, Rest auf den letzten Anteil der Zeile (Ausgleich in PHP mit `Money`, nicht in SQL).
- Ergebnis: Ertrag, Aufwand, Deckungsbeitrag je Konto, je Unterprojekt und gesamt; CSV und PDF über `ExportReportService`.

## Rechte und Modul

- `OrganizationModule::Projects = 'projects'`, `config/features.php` `'projects' => env('FEATURE_PROJECTS', false)`, alle Presets `false`. Eingeschaltet wird pro Organisation in den Einstellungen.
- `Permission::ProjectsView`, `ProjectsManage`, `ProjectsAllocate`. Owner, Admin, Accountant alle drei; Member und Viewer nur `projects.view`; Employee keines. Rollen-Sync per Migration nach dem Muster von `2026_10_16_090100_add_amend_posted_entries_permission`.
- Routen unter `feature:projects`: `/projects`, `/projects/{project}/report`, `/journal-entries/{entry}/allocations`, `/journal-entries/allocations/bulk`, `/allocation-reviews`.

## Oberfläche

- **Projekte** (neue Seite): Baum aus Bereichen und Projekten, Filter nach Status und Art, Formular mit allen Feldern.
- **Journal**: Spalte „Projekt“ im Bearbeitungsdialog pro Zeile (Auswahl) mit Schalter „Aufteilen“; Aktion „Zuordnung ändern“ in der Zeilenaktion jeder Buchung, auch gesperrter; Mehrfachauswahl mit „Projekt zuordnen“. Die bestehende Ansicht `cost_centers` zeigt die Projekte aus der neuen Tabelle.
- **Zuordnung prüfen**: Liste offener Prüfeinträge mit dem alten Stand und einem Knopf zum Zuordnen oder Verwerfen.
- **Projektauswertung**: Zeitraum, Projekt, Tabelle, Export.
- Sichtbar nur bei eingeschaltetem Modul und passendem Recht. Texte in allen vier Sprachen.

## API

- `GET/POST /api/v1/projects`, `GET/PATCH /api/v1/projects/{id}`.
- `GET /api/v1/journal-entries/{id}`: Zeilen tragen `allocations`, wenn das Modul eingeschaltet ist.
- `POST`/`PATCH /api/v1/journal-entries`: Zeilen dürfen `allocations` mitsenden.
- `PUT /api/v1/journal-entries/{id}/allocations`: ersetzt alle Zuordnungen der Buchung, Idempotency-Key Pflicht, kein `If-Match` (die Revision der Buchung ändert sich nicht).
- `GET /api/v1/projects/{id}/report?from=&to=`.
- `contract/api-contract.json` und `CHANGELOG.md` nachführen.

## Tests

- Vergleichstest (Kern der Abnahme): ein Datensatz mit Rechnungen, Spesen, Lohn, Bank, MWST-Abrechnung und Abschluss; alle Berichte werden gerechnet, dann werden alle Zeilen zugeordnet, umgeteilt und wieder gelöscht, und nach jedem Schritt müssen alle Berichte und die Archivprüfsummen identisch sein.
- Je Übernahmestelle ein Test.
- Sperrzustände: Zuordnung ändern bei `vat_locked`, `year_closed`, `archived`, Fachmodul-Buchung.
- Rechte, Mandantentrennung, Modul aus.
- Projektauswertung: Aufrollen, Abschluss ausgeklammert, Rundung.

## Risiken

| Risiko | Gegenmassnahme |
|---|---|
| Zuordnung geht beim Ändern verloren | Weitergabe über `JournalLineData`, Abgleich, Prüfliste, Test je Stelle |
| Eine Änderung berührt doch die Buchhaltung | eigener Service ohne Zugriff auf Zeilen, Vergleichstest |
| Konflikte mit dem Originalprojekt | neue Dateien statt Änderungen; Eingriffe in `LedgerService` und Aktionen auf je einen Aufruf beschränkt |
| Abfragen werden langsam | Indizes oben, Auswertung als eine Abfrage pro Zeitraum |
| Projekt mit Zuordnungen wird gelöscht | `restrictOnDelete` plus Prüfung im Controller |

## Rollout

Backup und Bestandsabgleich wie bei `.108`. Nach dem Rollout ist nichts sichtbar, bis das Modul in einer Organisation eingeschaltet wird. Zurück über das vorherige Image; die Migrationen sind additiv und mit `down()` umkehrbar.
