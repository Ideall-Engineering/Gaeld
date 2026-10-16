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

- [ ] T001 [P] Test: archivierte `TransactionLine` lässt sich weder ändern noch löschen (`tests/Feature/Accounting/ArchivedRecordLockTest.php` erweitern)
- [ ] T002 [P] Test: Archiv-Prüfsumme ändert sich, wenn sich eine Zeile ändert (`tests/Feature/Accounting/LegalArchivingTest.php` oder neu)
- [ ] T003 [P] Test: Jahresabschluss lehnt ab, wenn eine MWST-Abrechnung storniert und nicht neu gerechnet ist

### Implementation

- [ ] T004 `LocksArchivedTransactionLine` Observer anlegen und in `AppServiceProvider` registrieren
- [ ] T005 `LegalArchivingService::archiveDocument` lädt `lines` und nimmt sie in die Prüfsumme auf; bestehende Archive bleiben gültig, neue Version erst bei Neuarchivierung
- [ ] T006 `YearEndClosingAction::getUnsettledVatPeriods` behandelt Abrechnungen mit verbuchtem `REV-` als nicht abgerechnet

**Checkpoint**: Etappe 0 ist unabhängig auslieferbar.

---

## Phase 2: Fundament

- [ ] T007 Migration `add_posted_entry_edit_mode_to_organizations` (Standard `strict`)
- [ ] T008 Migration `add_revision_to_journal_entries` (Standard 1)
- [ ] T009 Migration `create_journal_entry_revisions_table` (siehe plan.md, ohne Fremdschlüssel auf `journal_entry_id`); `migrate` und `migrate:rollback` lokal durchspielen
- [ ] T010 [P] Enum `PostedEntryEditMode` und Cast auf `Organization`
- [ ] T011 [P] Modell `JournalEntryRevision` mit Sperre gegen `updating`/`deleting`, Scope `BelongsToOrganization`
- [ ] T012 [P] Enum `JournalEntryEditState` mit Übersetzungsschlüsseln
- [ ] T013 Berechtigung `AccountingAmendPosted` in `Permission`, Zuordnung in `Role` (Owner, Admin, Accountant), Seeder bzw. Sync der Rollenrechte
- [ ] T014 `VatEntryWriter` aus `LedgerService::createVatEntries`/`isVatReversalLine` herauslösen; `LedgerService` nutzt ihn; `tests/Unit/LedgerServiceTest.php` bleibt unverändert grün

---

## Phase 3: User Story 1 und 2, Ändern und Löschen (Priority: P1) 🎯

### Tests First

- [ ] T015 [P] [US1] `tests/Unit/Accounting/JournalEntryEditStateServiceTest.php`: ein Fall je Zustand, Datumswechsel offen nach gesperrt und gesperrt nach offen, Buchung ohne MWST-Zeilen in gesperrtem Quartal, Datum ohne Geschäftsjahr
- [ ] T016 [P] [US1] `tests/Feature/Accounting/AmendPostedJournalEntryTest.php`: Saldo, MWST-Einträge neu gebildet, MWST-Bericht sofort aktuell (Cache), Version gespeichert und hochgezählt, Begründung optional, Bankwarnung, `strict` lehnt ab, fehlende Berechtigung, fremde Organisation
- [ ] T017 [P] [US2] `tests/Feature/Accounting/DeletePostedJournalEntryTest.php`: Version `deleted` bleibt, Referenz reserviert, jede Verknüpfungsart lehnt ab, Lettrage lehnt ab
- [ ] T018 [P] [US1] Bestehende Unveränderlichkeitstests ausdrücklich auf `strict` setzen: `ManualJournalEntryTest` (:204, :223, :265), `LedgerServiceTest` (:230, :294), `JournalCorrectionTest` (:284), `JournalCorrectionWebTest` (:191), `JournalEntryLifecycleApiTest` (:136, :162), `ArchivedRecordLockTest` (:67, :109)

### Implementation

- [ ] T019 [US1] `JournalEntryEditStateService` mit `stateFor`, `assertAmendable`, `assertDeletable` (Prüfreihenfolge laut plan.md)
- [ ] T020 [US1] Referenzprüfung in `LedgerService` und `UpdateJournalDraftAction` um reservierte Referenzen aus `journal_entry_revisions` erweitern
- [ ] T021 [US1] `AmendPostedJournalEntryAction` inklusive `StaleRevisionException` und Bankwarnung
- [ ] T022 [US2] `DeletePostedJournalEntryAction`
- [ ] T023 [P] [US1] Ereignisse `JournalEntryAmended`, `JournalEntryDeleted`; `JournalEventSubscriber` schreibt `journal_events`
- [ ] T024 [US1] `JournalEntryPolicy::amend` und `::deletePosted`
- [ ] T025 [US1] `AccountingController::updateJournalEntry` und `::destroyJournalEntry` leiten verbuchte Buchungen weiter; Liste liefert `edit_state` und `revision`
- [ ] T026 [US1] `JournalEntries.vue`: Bearbeiten und Löschen für `open`, Feld „Begründung (optional)“, versteckte Version, Bestätigung bei Bankwarnung, Meldung bei Versionskonflikt
- [ ] T027 [P] [US1] Texte in `lang/{de,en,fr,it}/app.php`: `help_journal_text`, `confirm_post_journal_entry`, `cannot_edit_posted_entry` je Modus; neue Schlüssel für Zustände und Meldungen
- [ ] T028 [US1] Organisationseinstellung in `OrganizationSettingsController` und `Organizations/Settings.vue` mit Erklärtext

**Checkpoint**: Im Modus `flexible` lässt sich im Web ändern und löschen; im Modus `strict` sind alle bestehenden Tests grün.

---

## Phase 4: User Story 5, API (Priority: P2)

### Tests First

- [ ] T029 [P] [US5] `tests/Feature/Api/JournalEntryAmendApiTest.php`: 200, 409 `stale_revision`, 422 `entry_not_amendable`, 422 `bank_amount_change_unacknowledged`, Idempotenz, fehlender `If-Match`, Webhook
- [ ] T030 [P] [US5] `DELETE` verbuchter Buchungen: 204, 422 `entry_not_deletable`, Idempotenz

### Implementation

- [ ] T031 [US5] Route `PATCH /journal-entries/{journalEntry}` in `routes/api.php`; `JournalEntryApiController::update` über `executeMutation`
- [ ] T032 [US5] `JournalEntryApiController::destroy` für verbuchte Buchungen erweitern
- [ ] T033 [P] [US5] `JournalEntryResource`: `revision`, `edit_state`
- [ ] T034 [P] [US5] `WebhookEvent`: `journal_entry.updated`, `journal_entry.deleted` samt Dispatcher

---

## Phase 5: User Story 3 und 4, Sperren und Verlauf sichtbar (Priority: P2)

- [ ] T035 [P] [US3] Test: Liste und Detailseite liefern je Zustand den richtigen Grund und die richtigen Aktionen
- [ ] T036 [P] [US4] Test: Verlauf liefert alle Stände in Reihenfolge; Änderungsübersicht enthält gelöschte Buchungen; Tenant-Isolation
- [ ] T037 [US3] Schloss mit Grund und passender Aktion in `JournalEntries.vue` und `JournalEntryShow.vue`
- [ ] T038 [US4] Abschnitt „Verlauf“ in `JournalEntryShow.vue` mit Vergleich zweier Stände
- [ ] T039 [US4] Änderungsübersicht der letzten 30 Tage (Seite im Bereich Buchhaltung, Kachel im Dashboard)
- [ ] T040 [P] [US4] API `GET /journal-entries/{id}/revisions`

---

## Phase 6: User Story 6, Korrekturweg entlasten (Priority: P3)

- [ ] T041 [P] [US6] Tests: Vorschlag des ersten offenen Datums; erneute Korrektur einer verbuchten Ersatzbuchung; Filter `hide_corrections`
- [ ] T042 [US6] Vorschlag für `correction_date` in Dialog und API-Antwort der Vorbereitung
- [ ] T043 [US6] Erneute Korrektur einer Ersatzbuchung zulassen (Eindeutigkeit in `journal_corrections` anpassen, Migration rückrollbar)
- [ ] T044 [US6] Filter „Storno-Paare ausblenden“ in `AccountingController::journalEntries` und `JournalEntries.vue`

---

## Phase 7: Polish

- [ ] T045 Pint, PHPStan, gezielte Testläufe je Datei
- [ ] T046 `app/Domains/Accounting/README.md` um Bearbeitungszustände und Versionen ergänzen
- [ ] T047 Hinweise in `specs/007-accountant-api` zur Unveränderlichkeit mit Verweis auf 008 versehen

---

## Dependencies & Execution Order

* Phase 1 ist unabhängig und kann zuerst ausgeliefert werden.
* Phase 2 vor allen weiteren Phasen.
* Phase 3 vor Phase 4 und 5. Phase 4 und 5 sind untereinander unabhängig.
* Phase 6 hängt nur von Phase 2 ab, ist aber bewusst zuletzt geplant.

## Commit- und Rollback-Strategie

* Ein Commit pro Task oder eng zusammengehöriger Gruppe (z. B. T007 bis T009), nie ein Sammelcommit über eine Phase. Jeder Commit für sich lauffähig, zugehöriger Test grün.
* Jede Migration lokal mit `migrate` und `migrate:rollback` geprüft, nie gegen die Produktionsdatenbank.
* Produktiver Rückweg ohne Schema-Rollback: Modus der Organisation auf `strict` zurückstellen.
