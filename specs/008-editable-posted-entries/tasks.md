---

description: "Gäld task list: verbuchte Buchungen in offenen Perioden bearbeiten und löschen"

---

# Tasks: Verbuchte Buchungen in offenen Perioden bearbeiten

**Input**: [spec.md](spec.md) und [plan.md](plan.md).

**Scope rule**: Nur Handbuchungen im Bearbeitungszustand `open`. Kein neuer Schreibweg für Fachmodule, kein manuelles Sperrdatum, keine Änderung in abgerechneten MWST-Perioden. Wiederverwenden statt duplizieren: `LedgerService::validateDraftLines`, `VatPeriodLockService`, `JournalCorrectionEligibilityService`, `ApiIdempotencyService`, `JournalEntryData`/`JournalLineData`, der bestehende Bearbeitungsdialog in `JournalEntries.vue`.

**Format**: `[P]` = parallel möglich, `[USn]` = User Story aus spec.md.

---

## Phase 1: Etappe 0, Archivschutz schliessen

### Tests First

- [x] T001 [P] Test: archivierte `TransactionLine` lässt sich weder ändern noch löschen (`tests/Feature/Accounting/ArchivedRecordLockTest.php` erweitern)
- [x] T002 [P] Test: Archiv-Nutzlast und Prüfsumme enthalten die Zeilen (`LegalArchiveFiscalYearBoundaryTest::test_archived_journal_entry_payload_includes_its_lines`)
- [x] T003 [P] Test: Jahresabschluss lehnt ab, wenn eine MWST-Abrechnung storniert und nicht neu gerechnet ist

### Implementation

- [x] T004 `LocksArchivedTransactionLine` Observer anlegen und in `AppServiceProvider` registrieren
- [x] T005 `LegalArchivingService::archiveDocument` lädt `lines` und nimmt sie in die Prüfsumme auf; bestehende Archive bleiben gültig, neue Version erst bei Neuarchivierung
- [x] T006 `YearEndClosingAction::getUnsettledVatPeriods` behandelt Abrechnungen mit verbuchtem `REV-` als nicht abgerechnet

**Checkpoint**: Etappe 0 ist unabhängig auslieferbar.

---

## Phase 2: Fundament

- [x] T007 Migration `add_posted_entry_edit_mode_to_organizations` (Standard `strict`)
- [x] T008 Migration `add_revision_to_journal_entries` (Standard 1)
- [x] T009 Migration `create_journal_entry_revisions_table` (siehe plan.md, ohne Fremdschlüssel auf `journal_entry_id`); `migrate` und `migrate:rollback` lokal durchspielen
- [x] T010 [P] Enum `PostedEntryEditMode` und Cast auf `Organization`
- [x] T011 [P] Modell `JournalEntryRevision` mit Sperre gegen `updating`/`deleting`, Scope `BelongsToOrganization`
- [x] T012 [P] Enum `JournalEntryEditState` mit Übersetzungsschlüsseln
- [x] T013 Berechtigung `AccountingAmendPosted` in `Permission`, Zuordnung in `Role` (Owner, Admin, Accountant), Seeder bzw. Sync der Rollenrechte
- [x] T014 `VatEntryWriter` aus `LedgerService::createVatEntries`/`isVatReversalLine` herauslösen; `LedgerService` nutzt ihn; `LedgerServiceTest` und `JournalVatTest` bleiben unverändert grün (kein eigener `VatEntryWriterTest`, die Vorzeichenlogik ist dort abgedeckt)

---

## Phase 3: User Story 1 und 2, Ändern und Löschen (Priority: P1) 🎯

### Tests First

- [x] T015 [P] [US1] `tests/Feature/Accounting/JournalEntryEditStateTest.php` (braucht die Datenbank, deshalb Feature): ein Fall je Zustand, Datumswechsel offen nach gesperrt und gesperrt nach offen, Buchung ohne MWST-Zeilen in gesperrtem Quartal, Wechsel in ein abgeschlossenes Jahr
- [x] T016 [P] [US1] `tests/Feature/Accounting/AmendPostedJournalEntryTest.php`: Saldo, MWST-Einträge neu gebildet, MWST-Bericht sofort aktuell (Cache), Version gespeichert und hochgezählt, Begründung optional, Bankwarnung, `strict` lehnt ab, fehlende Berechtigung, fremde Organisation
- [x] T017 [P] [US2] `tests/Feature/Accounting/DeletePostedJournalEntryTest.php`: Version `deleted` bleibt, Referenz reserviert, jede Verknüpfungsart lehnt ab, Lettrage lehnt ab
- [x] T018 [P] [US1] Bestehende Unveränderlichkeitstests: laufen unverändert, weil `strict` der Standard ist; jeder neue `flexible`-Test setzt den Modus ausdrücklich

### Implementation

- [x] T019 [US1] `JournalEntryEditStateService` mit `stateFor`, `assertAmendable`, `assertDeletable` (Prüfreihenfolge laut plan.md)
- [x] T020 [US1] Referenzprüfung in `LedgerService` und `UpdateJournalDraftAction` um reservierte Referenzen aus `journal_entry_revisions` erweitern
- [x] T021 [US1] `AmendPostedJournalEntryAction` inklusive `StaleRevisionException` und Bankwarnung
- [x] T022 [US2] `DeletePostedJournalEntryAction`
- [x] T023 [P] [US1] Ereignisse `JournalEntryAmended`, `JournalEntryDeleted`; `JournalEventSubscriber` schreibt `journal_events`
- [x] T024 [US1] `JournalEntryPolicy::amend` und `::deletePosted`
- [x] T025 [US1] `AccountingController::updateJournalEntry` und `::destroyJournalEntry` leiten verbuchte Buchungen weiter; Liste liefert `edit_state` und `revision`
- [x] T026 [US1] `JournalEntries.vue`: Bearbeiten und Löschen für `open`, Feld „Begründung (optional)“, versteckte Version, Bestätigung bei Bankwarnung, Meldung bei Versionskonflikt
- [x] T027 [P] [US1] Texte in `lang/{de,en,fr,it}/app.php`: `help_journal_text`, `confirm_post_journal_entry`, `cannot_edit_posted_entry` je Modus; neue Schlüssel für Zustände und Meldungen
- [x] T028 [US1] Organisationseinstellung in `OrganizationSettingsController` und `Organizations/Settings.vue` mit Erklärtext

**Checkpoint**: Im Modus `flexible` lässt sich im Web ändern und löschen; im Modus `strict` sind alle bestehenden Tests grün.

---

## Phase 4: User Story 5, API (Priority: P2)

### Tests First

- [x] T029 [P] [US5] `tests/Feature/Api/JournalEntryAmendApiTest.php`: 200, 409 `stale_revision`, 422 `entry_not_amendable`, 422 `bank_amount_change_unacknowledged`, Idempotenz, fehlender `If-Match`, Webhook
- [x] T030 [P] [US5] `DELETE` verbuchter Buchungen: 204, 422 `entry_not_deletable`, Idempotenz

### Implementation

- [x] T031 [US5] Route `PATCH /journal-entries/{journalEntry}` in `routes/api.php`; `JournalEntryApiController::update` über `executeMutation`
- [x] T032 [US5] `JournalEntryApiController::destroy` für verbuchte Buchungen erweitern
- [x] T033 [P] [US5] `JournalEntryResource`: `revision`, `edit_state`
- [x] T034 [P] [US5] `WebhookEvent`: `journal_entry.updated`, `journal_entry.deleted` samt Dispatcher

---

## Phase 5: User Story 3 und 4, Sperren und Verlauf sichtbar (Priority: P2)

- [x] T035 [P] [US3] Test: Liste und Detailseite liefern je Zustand den richtigen Grund und die richtigen Aktionen
- [x] T036 [P] [US4] Test: Verlauf liefert alle Stände in Reihenfolge; Änderungsübersicht enthält gelöschte Buchungen; Tenant-Isolation
- [x] T037 [US3] Schloss mit Grund und passender Aktion in `JournalEntries.vue` und `JournalEntryShow.vue`
- [x] T038 [US4] Abschnitt „Verlauf“ in `JournalEntryShow.vue` mit Vergleich zweier Stände
- [x] T039 [US4] Änderungsübersicht der letzten 30 Tage als Seite `/accounting/journal-entries/changes`; statt einer Dashboard-Kachel ein Link mit Anzahl über der Journalliste
- [x] T040 [P] [US4] API `GET /journal-entries/{id}/revisions`

---

## Phase 6: User Story 6, Korrekturweg entlasten (Priority: P3)

- [x] T041 [P] [US6] Tests: Vorschlag des ersten offenen Datums; erneute Korrektur einer verbuchten Ersatzbuchung; Filter `hide_corrections`
- [x] T042 [US6] Vorschlag für `correction_date` in Dialog und API-Antwort der Vorbereitung
- [x] T043 [US6] Erneute Korrektur einer Ersatzbuchung zulassen: Die Eindeutigkeitsregeln erlaubten das bereits, nur die Oberfläche blendete den Knopf aus; keine Migration nötig. Bei Ketten gewinnt die neueste Korrektur für die Rollenanzeige
- [x] T044 [US6] Filter „Storno-Paare ausblenden“ in `AccountingController::journalEntries` und `JournalEntries.vue`

---

## Phase 7: Polish

- [x] T045 Pint, PHPStan, gezielte Testläufe je Datei
- [x] T046 `app/Domains/Accounting/README.md` um Bearbeitungszustände und Versionen ergänzen
- [x] T047 Hinweis in `specs/007-accountant-api/spec.md` zur Unveränderlichkeit mit Verweis auf 008 versehen

---

## Dependencies & Execution Order

* Phase 1 ist unabhängig und kann zuerst ausgeliefert werden.
* Phase 2 vor allen weiteren Phasen.
* Phase 3 vor Phase 4 und 5. Phase 4 und 5 sind untereinander unabhängig.
* Phase 6 hängt nur von Phase 2 ab, ist aber bewusst zuletzt geplant.

## Zusätzlich umgesetzt (beim Bauen gefunden)

- [x] T048 Webformular für Buchungen trägt die MWST-Felder jeder Zeile (`JournalLinesEditor.vue`); vorher ging die MWST einer per API erfassten Buchung beim Bearbeiten im Web still verloren. Gilt auch für den Ersatzentwurf der geführten Korrektur.
- [x] T049 API-Berechtigung über das tokenbewusste Gate (`attemptAmend`) und neues Token-Recht `accounting.amend-posted`, damit ein eingeschränktes Token nicht ändern kann; Test dafür.
- [x] T050 `JournalEntryRevisionPolicy` (nur lesen) für den Sicherheitstest, der jedem organisationsgebundenen Modell eine Policy abverlangt.
- [x] T051 Eröffnungsbuchungen (Referenz `OPENING-` oder Buchung gegen das Eröffnungskonto) gelten als systemerzeugt und bleiben gesperrt.
- [x] T052 Vorschlag für das Korrekturdatum (`firstOpenDateOnOrAfter`) springt über abgerechnete Quartale und abgeschlossene Jahre.

## Commit- und Rollback-Strategie

* Ein Commit pro Task oder eng zusammengehöriger Gruppe (z. B. T007 bis T009), nie ein Sammelcommit über eine Phase. Jeder Commit für sich lauffähig, zugehöriger Test grün.
* Jede Migration lokal mit `migrate` und `migrate:rollback` geprüft, nie gegen die Produktionsdatenbank.
* Produktiver Rückweg ohne Schema-Rollback: Modus der Organisation auf `strict` zurückstellen.
