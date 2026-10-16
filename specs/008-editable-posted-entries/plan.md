# Implementation Plan: Verbuchte Buchungen in offenen Perioden bearbeiten

**Spec**: [spec.md](spec.md) · **Tasks**: [tasks.md](tasks.md) · **Branch**: `feature/editable-posted-entries` ab `deployment`

## Summary

Die Sperre verbuchter Buchungen wandert vom Verbuchen zu den fachlichen Ereignissen MWST-Abrechnung, Jahresabschluss und Archivierung. Ein neuer `JournalEntryEditStateService` bestimmt den Bearbeitungszustand; zwei neue Actions ändern bzw. löschen verbuchte Buchungen und schreiben vorher einen vollständigen Stand in `journal_entry_revisions`. Der Modus ist pro Organisation schaltbar und steht standardmässig auf `strict`, damit sich ohne bewusste Umstellung nichts ändert.

## Technical Context

* Laravel, PostgreSQL, Inertia mit Vue 3, PHPUnit.
* Buchungslogik in `app/Domains/Accounting`, API in `app/Domains/Api`, Routen in `routes/web/accounting.php` und `routes/api.php`.
* Saldi werden nicht gespeichert, sondern live aus `transaction_lines` gerechnet und unter den Tags `org:{id}:ledger`, `:reports`, `:dashboard` gecacht (`LedgerQueryService.php:51-75`, `LedgerService::flushCache` :238).
* MWST-Einträge (`vat_entries`) werden beim Verbuchen pro Zeile erzeugt (`LedgerService::createVatEntries` :416, privat). Der MWST-Bericht rechnet live daraus.
* Die MWST-Sperre ist datumsbasiert (`VatPeriodLockService::isLocked`), wird aber nur über Modellereignisse von `VatEntry` erzwungen (`VatEntry.php:50-65`).
* Die Archivsperre greift über `LocksArchivedRecord` nur für `JournalEntry`, nicht für `TransactionLine` (`AppServiceProvider.php:218-227`).

## Existing Codebase Impact

### Neu

| Datei | Inhalt |
|---|---|
| `Accounting/Enums/JournalEntryEditState.php` | Zustände aus FR-002 mit Übersetzungsschlüssel für den Grund |
| `Accounting/Services/JournalEntryEditStateService.php` | `stateFor(JournalEntry): JournalEntryEditState`, `assertAmendable(entry, ?newDate)`, `assertDeletable(entry)` |
| `Accounting/Models/JournalEntryRevision.php` | nur `creating` erlaubt, `updating`/`deleting` werfen |
| `Accounting/Actions/AmendPostedJournalEntryAction.php` | Ablauf siehe unten |
| `Accounting/Actions/DeletePostedJournalEntryAction.php` | Ablauf siehe unten |
| `Accounting/Services/VatEntryWriter.php` | aus `LedgerService::createVatEntries` und `isVatReversalLine` herausgelöst, damit Verbuchen und Ändern dieselbe Logik nutzen |
| `Accounting/Events/JournalEntryAmended.php`, `JournalEntryDeleted.php` | Ereignisse für `journal_events` und Webhooks |
| `Support/Observers/LocksArchivedTransactionLine.php` | sperrt Zeilen, deren Buchung `archived_at` trägt |
| `Accounting/Services/JournalEntryRevisionRecorder.php` | schreibt den vollständigen Stand vor einer Änderung |
| `Accounting/Policies/JournalEntryRevisionPolicy.php` | Versionen nur lesen |
| `Api/Controllers/JournalEntryRevisionApiController.php` | Versionen je Buchung und über alle Buchungen |
| `resources/js/Components/Accounting/JournalLinesEditor.vue`, `JournalEntryFormModal.vue`, `JournalEntryHistory.vue` | Zeilen mit MWST, gemeinsames Formular, Verlauf |
| `resources/js/Pages/Accounting/JournalEntryChanges.vue` | Änderungsübersicht |

### Geändert

| Stelle | Änderung |
|---|---|
| `JournalEntryPolicy` update/delete (:34-54) | neue Methoden `amend`/`deletePosted`; bestehende Draft-Regeln bleiben |
| `AccountingController::updateJournalEntry` (:418-427), `destroyJournalEntry` (:484) | verbuchte Buchungen an die neuen Actions weiterleiten statt abweisen |
| `AccountingController::journalEntries` (:78-123) | pro Zeile `edit_state`, `revision`; Filter `hide_corrections` |
| `JournalEntryApiController` (:214-237), `routes/api.php` (:107-112) | `update` neu, `destroy` erweitert |
| `JournalEntryResource` | `revision`, `edit_state` |
| `LedgerService` | nutzt `VatEntryWriter`; `guardClosedFiscalYear` (:509) wird öffentlich oder in den Zustandsdienst verlegt; Referenzprüfung (:343) berücksichtigt reservierte Referenzen |
| `LegalArchivingService::archiveDocument` (:42-107) | Zeilen in die Prüfsumme aufnehmen |
| `YearEndClosingAction::getUnsettledVatPeriods` (:226-257) | stornierte Abrechnungen gelten als nicht abgerechnet |
| `Permission`, `Role`, `TokenPermissionMap` | `AccountingAmendPosted` für Owner, Admin, Accountant; auch als Token-Recht |
| `WebhookEvent` | `journal_entry.updated`, `journal_entry.deleted` |
| `JournalEntries.vue`, `JournalEntryShow.vue` | Bearbeiten/Löschen für verbuchte Buchungen, Sperr-Hinweis, Verlauf, Filter |
| `lang/{de,en,fr,it}/app.php` | `help_journal_text` (:757), `confirm_post_journal_entry` (:2588), `cannot_edit_posted_entry` (:2586) je Modus; neue Schlüssel |

### Bewusst unverändert

* Der geführte Korrekturweg bleibt für gesperrte Perioden bestehen. Er wird in Etappe 3 nur entschärft.
* Fachmodule (Rechnung, Spesen, Lohn, Bank, Abschreibung) bekommen keinen neuen Schreibweg.

## Fachlicher Ablauf

### Bearbeitungszustand (Reihenfolge der Prüfung)

1. Modus `strict` oder Buchung nicht verbucht: bisherige Regeln, der Dienst ist nicht zuständig.
2. `archived_at` gesetzt: `archived`.
3. Geschäftsjahr des Datums `Closed`: `fiscal_year_closed`.
4. `VatPeriodLockService::isLocked(org, date)`: `vat_locked`.
5. `JournalCorrectionEligibilityService::isSourceManaged` oder Verknüpfung zu `depreciation_entries`: `source_managed`.
6. Teil einer `journal_corrections`-Zeile (Original, Storno, Ersatz) oder `reversal_of_entry_id` gesetzt bzw. selbst storniert: `correction_bound`.
7. Referenz beginnt mit `OPENING-`, `REV-`, `VAT-SETTLEMENT-` oder Buchung trägt Typ `year_end_closing`/`historical_summary`: `system_generated`.
8. Eine Zeile trägt `lettrage_key`: `lettered`.
9. Sonst: `open`.

Für Datumsänderungen werden Schritte 3 und 4 zusätzlich für das neue Datum geprüft. Ein Datum ohne Geschäftsjahr wird abgelehnt wie beim Verbuchen.

### Ändern (`AmendPostedJournalEntryAction`)

In einer Transaktion:

1. Buchung mit `lockForUpdate` laden, `revision` gegen die mitgeschickte Version prüfen (sonst `StaleRevisionException`, HTTP 409).
2. `assertAmendable(entry, newDate)`.
3. `LedgerService::validateDraftLines`, Saldo, Referenz (inklusive reservierter Referenzen).
4. Snapshot in `journal_entry_revisions` (`kind = updated`, `revision = alte Nummer`).
5. MWST-Einträge **einzeln** über das Modell löschen, damit der Sperr-Hook greift und das Aktivitätsprotokoll schreibt; danach Zeilen ebenso einzeln löschen.
6. Kopf aktualisieren, Zeilen neu anlegen, MWST-Einträge über `VatEntryWriter` neu bilden.
7. `revision` hochzählen.
8. Nach dem Commit: `flushCache`, `JournalEntryAmended` (schreibt `journal_events`, löst Webhook aus).

Ändert sich auf einer Zeile eines Bankkontos (ein Konto, auf das `bank_accounts.account_id` verweist) der Betrag oder wird ein Bankbetrag auf ein anderes Datum verschoben, liefert die Vorprüfung eine Warnung; die Oberfläche verlangt eine Bestätigung, die API ein Feld `acknowledge_bank_amount_change: true`.

### Löschen (`DeletePostedJournalEntryAction`)

Wie Ändern bis Schritt 4 (`kind = deleted`), dann MWST-Einträge, Zeilen und Buchung einzeln löschen. `journal_events` der Buchung fallen per Kaskade weg; die Versionstabelle bleibt, weil sie keinen kaskadierenden Fremdschlüssel hat.

## Constitution Check

- [x] Owning Domain ist Accounting; die Invarianten (ausgeglichen, MWST konsistent, keine Änderung in gesperrten Perioden) sind explizit.
- [x] Bestehende Services werden wiederverwendet (`LedgerService::validateDraftLines`, `VatPeriodLockService`, `JournalCorrectionEligibilityService`, `ApiIdempotencyService`).
- [x] Organisation Scope: alle Abfragen über `BelongsToOrganization`; die Versionstabelle trägt `organization_id`.
- [x] Autorisierung über eigene Berechtigung und Policy, Fehler mit stabilen Codes.
- [x] Standard `strict` hält das Verhalten für bestehende Installationen und das Original konstant.
- [x] Test-Gates umfassen jeden Sperrzustand, beide Richtungen des Datumswechsels, Versionskonflikt und Tenant-Isolation.
- [x] Alle Migrationen sind additiv und rückrollbar.

## Etappen und Abnahmetore

### Etappe 0: Archivschutz schliessen

Archivierte Zeilen sperren, Zeilen in die Archiv-Prüfsumme aufnehmen, Jahresabschluss-Prüfung für stornierte MWST-Abrechnungen korrigieren. Unabhängig vom Modus, ein Gewinn auch ohne die übrigen Etappen.

**Abnahme**: Eine archivierte Zeile lässt sich weder ändern noch löschen; eine Zeilenänderung verändert die Prüfsumme; ein Jahr mit storniertem, nicht neu gerechnetem Quartal lässt sich nicht abschliessen.

### Etappe 1: Ändern und Löschen mit Versionen (Web und API)

Migration, Zustandsdienst, beide Actions, Policy, Controller, API, minimale Oberfläche (Bearbeiten und Löschen im bestehenden Dialog, Begründungsfeld, Bankwarnung), Texte.

**Abnahme**: Alle Akzeptanzszenarien aus User Story 1, 2 und 5; alle bestehenden Tests grün im Modus `strict`.

### Etappe 2: Sperren und Verlauf sichtbar machen

Sperr-Hinweis mit Grund und passender Aktion, Verlauf auf der Detailseite mit Vergleich zweier Stände, Änderungsübersicht der letzten 30 Tage (inklusive gelöschter Buchungen), API `GET /journal-entries/{id}/revisions`.

**Abnahme**: User Story 3 und 4. Umgesetzt mit Link und Anzahl über der Journalliste statt einer Dashboard-Kachel.

### Etappe 3: Korrekturweg entlasten

Korrekturdatum schlägt das erste offene Datum vor; eine verbuchte Ersatzbuchung darf erneut korrigiert werden (die bestehenden Eindeutigkeitsregeln erlauben die Kette bereits, es fehlte nur der Knopf); Filter „Storno-Paare ausblenden“.

**Abnahme**: User Story 6.

## Data and Contract Changes

### Data Model

```
organizations
  + posted_entry_edit_mode  string(16) not null default 'strict'

journal_entries
  + revision                unsigned integer not null default 1

journal_entry_revisions (neu)
  id                        uuid pk
  organization_id           uuid not null, index, fk organizations
  journal_entry_id          uuid not null, index, OHNE fk (überdauert das Löschen)
  revision                  unsigned integer not null
  kind                      string(16) not null  -- updated | deleted
  reference                 string(100) null, index (für Referenzreservierung)
  snapshot                  jsonb not null       -- Kopf, Zeilen, MWST-Einträge
  reason                    text null
  causer_id                 fk users null on delete set null
  source                    string(16) not null  -- web | api
  idempotency_key           string null
  created_at                timestamp not null
  unique (journal_entry_id, revision)
```

### HTTP/API Contract

| Methode | Pfad | Body | Antworten |
|---|---|---|---|
| `PATCH` | `/api/v1/journal-entries/{id}` | `date`, `reference`, `description`, `lines[]` (Format wie `store`), `reason?`, `acknowledge_bank_amount_change?` | 200 mit Ressource; 409 `stale_revision` mit `current_revision`; 422 `entry_not_editable` mit `edit_state`; 422 `bank_amount_change_unacknowledged`; 428 `revision_required` |
| `DELETE` | `/api/v1/journal-entries/{id}` | `reason?` | 204; 409 `stale_revision`; 422 `entry_not_editable` mit `edit_state`; 428 `revision_required` |
| `GET` | `/api/v1/journal-entries/{id}/revisions` | | 200, Stände der Buchung, älteste zuerst |
| `GET` | `/api/v1/journal-entry-revisions` | `kind?`, `journal_entry_id?`, `since?` | 200, Stände über alle Buchungen, neueste zuerst; einziger Weg zum letzten Stand einer gelöschten Buchung |

Header: `Idempotency-Key` (Pflicht für `PATCH` und `DELETE` verbuchter Buchungen), `If-Match: <revision>` (Pflicht). Die Ressource enthält neu `revision` und `edit_state`. Webhooks `journal_entry.updated` und `journal_entry.deleted` mit alter und neuer Version.

### Frontend States

| Zustand | Journalliste | Detailseite |
|---|---|---|
| Entwurf | wie heute | wie heute |
| `open` | Bearbeiten, Löschen, Badge „geändert“ ab Version 2 | Bearbeiten, Löschen, Verlauf |
| `vat_locked`, `fiscal_year_closed` | Schloss, Korrigieren, Stornieren | Grund, Korrektur erfassen |
| `archived` | Schloss | Grund, nur lesen |
| `source_managed` | Schloss | Grund mit Verweis aufs Modul |
| `correction_bound`, `system_generated`, `lettered` | Schloss | Grund |

## Test Strategy

* **Unit**: `JournalEntryEditStateServiceTest` mit einem Fall je Zustand und je Richtung des Datumswechsels; `VatEntryWriterTest` als Regression der bisherigen Vorzeichenlogik.
* **Feature Web**: `AmendPostedJournalEntryTest`, `DeletePostedJournalEntryTest` (Saldo, MWST-Bericht, Cache, Version, Referenzreservierung, Bankwarnung, Berechtigung, Tenant-Isolation).
* **Feature API**: `JournalEntryAmendApiTest` (409, 422, Idempotenz, Webhook).
* **Bestehende Tests**: Folgende prüfen die Unveränderlichkeit und laufen künftig ausdrücklich im Modus `strict`: `ManualJournalEntryTest` (:204, :223, :265), `LedgerServiceTest` (:230, :294), `JournalCorrectionTest` (:284), `JournalCorrectionWebTest` (:191), `JournalEntryLifecycleApiTest` (:136, :162), `ArchivedRecordLockTest` (:67, :109). Zu jedem kommt ein Gegenstück für `flexible`.
* Testläufe gezielt pro Datei, nicht mit breiten Filtern.

## Rollout and Operations

1. Migration ist additiv; nach dem Rollout ist der Modus überall `strict`, es ändert sich nichts.
2. Umstellung einer Organisation über die Organisationseinstellungen (`OrganizationSettingsController`, `Organizations/Settings.vue`), geschützt wie die übrigen Einstellungen über `authorize('update', $organization)`.
3. Rückweg ohne Schema-Rollback: Modus zurück auf `strict`. Bereits geänderte Buchungen und ihre Versionen bleiben gültig.
4. Vor der ersten Umstellung eine Datenbanksicherung; danach Saldi gegen den Stand vorher prüfen.
5. Verfahrensbeschreibung für die eigene Buchführung (wann ändern, wann korrigieren) gehört in die Betriebsdokumentation der Instanz, nicht ins Repository.

## Risiken

| Risiko | Massnahme |
|---|---|
| Umgehung der MWST-Sperre über Buchungen ohne MWST-Zeilen oder Datumswechsel | Datumsbasierte Prüfung im Zustandsdienst für altes und neues Datum, eigene Tests |
| Aktivitätsprotokoll unvollständig (Sammellöschungen) und auf 365 Tage begrenzt | Eigene Versionstabelle ohne Bereinigung; Löschen nur einzeln über Modelle |
| Veraltete Berichte durch Cache | `flushCache` nach jeder Änderung, Test über den MWST-Bericht |
| Waisen in Fachmodulen beim Löschen | Löschen nur im Zustand `open`, der alle Verknüpfungen ausschliesst |
| Lettrage mit toten Zeilen-IDs | Zustand `lettered` sperrt |
| Bankkonto weicht vom Auszug ab | Warnung mit Bestätigungspflicht |
| Gleichzeitige Änderungen | `revision` mit `If-Match` |
| Wiederverwendete Referenzen | Reservierung über `journal_entry_revisions.reference` |
| Stille Änderung bereits weitergegebener Zahlen | Verlauf und Änderungsübersicht (Etappe 2) |
| Abstand zum Original | Neue Logik in eigenen Klassen, Standard `strict`, Kern nur mit Weichen |

## Bekannte Grenzen (nach Review)

* **Gleichzeitiges Ändern und Abrechnen.** `PostVatSettlementAction` rechnet den MWST-Bericht ausserhalb einer Sperre. Eine Änderung, die genau zwischen Bericht und Abrechnung gespeichert wird, wäre in der Abrechnung nicht enthalten. Bei einer Organisation mit einer buchenden Person praktisch ausgeschlossen; sauber lösen liesse es sich mit einer gemeinsamen Sperre pro Organisation in Ändern, Löschen, Abrechnung und Jahresabschluss.
* **Ereignisse gelöschter Buchungen.** `journal_events` löscht seine Zeilen mit der Buchung (bestehender Fremdschlüssel). Massgeblich für die Nachvollziehbarkeit ist die Versionstabelle, die erhalten bleibt.
* **Systemreferenzen.** Referenzen mit `REV-`, `VAT-SETTLEMENT-`, `OPENING-` und `DISP-` lassen sich über Formular und API nicht mehr selbst vergeben (`NotSystemReference`), weil eine handgemachte `REV-VAT-SETTLEMENT-…` sonst ein abgerechnetes Quartal entsperren würde. Ältere, nur über die Referenz verknüpfte Stornos sperren ihr Original.

## Complexity Tracking

| Abweichung | Begründung |
|---|---|
| Eigene Versionstabelle neben dem Aktivitätsprotokoll | Das Protokoll speichert Kopf und Zeilen getrennt, verliert Sammellöschungen und wird nach 365 Tagen bereinigt; für die Nachvollziehbarkeit braucht es vollständige Stände |
| `journal_entry_revisions.journal_entry_id` ohne Fremdschlüssel | Versionen müssen das Löschen der Buchung überdauern |
