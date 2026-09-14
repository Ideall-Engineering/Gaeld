---

description: "Gäld task list for the Budgets focus package, pulled forward from Etappe 8"

---

# Tasks: Fokuspaket Budgets per API (vorgezogen aus Etappe 8)

**Input**: [plan.md](plan.md), speziell `### Etappe 8 – Optionale Fachmodule`
(erster Lieferpunkt „Budgets") sowie `## Umsetzungsstand`.

**Companion**: [tasks.md](tasks.md) deckt Etappe 0 und das Korrektur-Fokuspaket
ab, beide abgeschlossen. Diese Datei ergänzt jene, sie ersetzt sie nicht.
Task-IDs beginnen bei T101, damit es keine Kollision mit T001–T064 gibt.

**Scope rule**: Nur Budgets. Keine weiteren Etappe-8-Module (Kostenstellen,
Fremdwährung, Steuerdeklarationen, Konsolidierung), keine Stammdaten aus
Etappe 1, keine Geschäftsjahr-Endpunkte, keine asynchronen Aufträge. Kein
zweiter Schreibpfad neben `BudgetController` — der Webcontroller und das Modul
rufen nach T102 dieselbe Domain-Action.

**Warum vorgezogen**: Budgets sind der einzige Etappe-8-Punkt ohne fachliche
Vorbedingung aus den Etappen 1–7. `/api/v1/accounts` existiert lesend,
`fiscal_year` ist eine schlichte Jahreszahl, ein Budget hängt direkt an der
Organisation. Der Plan gibt Etappe 8 ausdrücklich frei, „nach tatsächlicher
Nutzung" zu priorisieren. Gleiches Vorgehen wie beim Korrektur-Fokuspaket, das
aus Etappe 2 vorgezogen wurde, während Etappe 1 offen blieb.

## Architekturentscheid: Adressierung über den natürlichen Schlüssel

Budgets werden als `{account_code}/{fiscal_year}` adressiert, nicht über eine ID.

**Grund**: `budgets` besitzt keine `uuid`-Spalte, nur eine ganzzahlige `id`
(`database/migrations/2026_03_27_100000_create_budgets_table.php`). Die
API-Konvention verlangt UUIDs als öffentliche IDs, und
`App\Domains\Api\Resources\AccountResource` gibt als `id` ausschliesslich die
Konto-UUID aus — das ganzzahlige `account_id`, das die Budget-Tabelle verlangt,
ist über die API also überhaupt nicht erreichbar. Eine ID-basierte Route
erzwänge entweder eine Kernmigration für UUIDs auf `budgets` oder das
Offenlegen interner Ganzzahl-IDs. Beides entfällt hier: `(organization_id,
account_id, fiscal_year)` ist bereits ein Unique-Index, und
`App\Domains\Api\Services\AccountCodeResolver` löst Kontocodes schon für das
Journal-API auf.

**Folge**: `PUT` ist ein Upsert über `updateOrCreate` und damit von Natur aus
idempotent; `DELETE` ebenso. Es braucht weder optimistische Parallelitätskontrolle
(T017, bewusst zurückgestellt) noch ein tenant-sicheres verschachteltes Binding
(T014, bewusst zurückgestellt) noch eine neue Kernmigration.

**Endpunkte**:

```text
GET    /api/v1/budgets?fiscal_year=2026
GET    /api/v1/budgets/{account_code}/{fiscal_year}
PUT    /api/v1/budgets/{account_code}/{fiscal_year}
DELETE /api/v1/budgets/{account_code}/{fiscal_year}
```

---

## Phase 1: Kern-Seams (Track A)

**Purpose**: Die Schreiblogik steckt heute im Webcontroller. Ohne diesen Schritt
müsste das Modul sie duplizieren — genau die Drift, die plan.md ausschliesst.

- [x] T101 `app/Domains/Accounting/Actions/UpsertBudgetAction.php` — nimmt Organisation, `Account`, `fiscal_year` und `monthly_amount`, führt das `updateOrCreate` auf dem Unique-Schlüssel aus, gibt das `Budget` zurück. Reine Verschiebung der vorhandenen Logik aus `BudgetController::store()`/`update()`, keine Verhaltensänderung.
- [x] T102 `app/Domains/Accounting/Actions/DeleteBudgetAction.php` — analog zu `BudgetController::destroy()`.
- [x] T103 `app/Domains/Accounting/Controllers/BudgetController.php` auf T101/T102 umstellen; `store`, `update` und `destroy` rufen nur noch die Action und bauen die Redirect-Antwort. Gleiches Muster wie T030 (`UpdateJournalDraftAction`). Bestehende Webtests müssen unverändert grün bleiben.
- [x] T104 `app/Http/Middleware/Api/HandleApiIdempotency::isHandledByDomainController()` — den Präfix `api.accountant-api.` auf `api.accountant-api.journal-` verengen. Der heutige Blankettausschluss stammt aus T049 und war für die Korrektur-Endpunkte gedacht; er würde jeden künftigen Modulendpunkt still ohne Idempotenzbehandlung lassen. Budgets sollen die normale Middleware-Behandlung erhalten. **Regressionstest zwingend**: die fünf Korrektur-Endpunkte behalten ihr heutiges Verhalten, `Idempotency-Key` bleibt dort Pflicht (`idempotency_key_required`).
- [x] T105 [P] `routes/web/accounting.php` — die vier Budget-Webrouten in eine `Route::middleware('feature:budgets')`-Gruppe fassen. **Vorgefundene Lücke, nicht von diesem Paket verursacht**: `OrganizationModule::Budgets` wird den Eigentümern unter Einstellungen → Module als Schalter angeboten und `config/features.php:28` kennt den Schlüssel, aber keine Budget-Route prüft ihn — anders als `tax_declaration`, `analytical`, `multi_currency` und `consolidation` in derselben Datei. Ohne diesen Task hiesse ein Gate auf der API-Route, dass die API strenger ist als das Web. Separat committen, für Upstream geeignet.

**Gate erfüllt (2026-09-14)**: `tests/Feature/Accounting/BudgetFlowTest.php` 9/9, `plugins/accountant-api/tests` 18/18, `tests/Feature/Api` 82/82, `tests/Feature/Plugins` 13/13, Pint und PHPStan sauber auf allen berührten Dateien.

**Abweichungen beim Umsetzen**:

- **T105, Befund präziser als ursprünglich formuliert**: `CheckFeatureFlag` hält im Docblock ausdrücklich fest, dass in CE nur auf Installationsebene gesperrt wird und die Eigentümer-Schalter unter Einstellungen → Module bewusst nur die Oberfläche ausblenden. Der Schalter ist also kein wirkungsloser Knopf, sondern absichtlich UI-only. Die echte Lücke war enger: `FEATURE_BUDGETS` (`config/features.php:28`) blieb auf jeder Budget-Route wirkungslos, während die Geschwister `tax_declaration`, `analytical`, `multi_currency` und `consolidation` in derselben Routendatei sauber gesperrt sind. Genau das ist jetzt behoben, mitsamt Test `test_budget_routes_are_closed_when_the_feature_is_disabled`.
- **T101 nimmt ein `Account`-Modell statt einer ID** entgegen. So kann der Modul-Bridge das über `AccountCodeResolver` bereits aufgelöste Konto direkt durchreichen, ohne es ein zweites Mal zu laden.
- **T103**: `Account::findOrFail()` ergab an der typisierten Parametergrenze einen PHPStan-Fehler (`Account|Collection`). Ersetzt durch `Account::whereKey(...)->firstOrFail()`. Der bestehende Aufruf in `LettrageController` bleibt unberührt — er trifft die Grenze nicht.
- **T104**: Der verengte Präfix lautet `api.accountant-api.journal-`; er deckt sowohl `journal-entries.corrections.store` als auch alle `journal-corrections.*` ab. Regressionsnachweis sind die drei bestehenden Tests in `JournalCorrectionIdempotencyTest`, die weiterhin grün sind. Ein Test, der beweist, dass eine *Nicht*-Korrektur-Modulroute nun durch die Middleware läuft, ist erst mit den Budget-Routen möglich und steckt in T109.

---

## Phase 2: Tests First (Track B)

**Purpose**: Vertrag, Sicherheit und Idempotenz festnageln, bevor Code entsteht.

- [x] T106 [P] [US1] `plugins/accountant-api/tests/Feature/BudgetReadTest.php` — Liste mit und ohne `fiscal_year`-Filter, Pagination, Einzelabruf über `{account_code}/{fiscal_year}`, `404` für ein nicht gesetztes Budget, Antwortform (`data`, `links`, `meta`).
- [x] T107 [P] [US2] `plugins/accountant-api/tests/Feature/BudgetWriteTest.php` — `PUT` legt an (`201`) und aktualisiert (`200`), zweimal dasselbe `PUT` erzeugt genau eine Zeile, `DELETE` gibt `204` und ist auf ein bereits gelöschtes Budget stabil, Validierungsfehler für unbekannten Kontocode, inaktives Konto, `fiscal_year` ausserhalb 2000–2099 und negativen Betrag.
- [x] T108 [P] [US2] `plugins/accountant-api/tests/Security/BudgetSecurityTest.php` — fremdes Kontokürzel einer anderen Organisation ergibt `404` statt `403`, ein auf `accounting.view` beschränktes Token darf nicht schreiben, ein Token ohne `accounting.view` sieht nichts, abgeschaltetes `feature:budgets` sperrt alle vier Routen. Für den Cross-Tenant-Fall die in T041 dokumentierte Falle beachten: keine zweite `withToken()`-Fixture im selben Test.
- [x] T109 [P] [US2] `plugins/accountant-api/tests/Feature/BudgetIdempotencyTest.php` — Wiederholung mit identischem `Idempotency-Key` liefert dieselbe Antwort ohne zweite Zeile; derselbe Schlüssel mit abweichender Nutzlast ergibt `409`. **Bewusster Unterschied zu den Korrektur-Endpunkten**: der Header ist hier nicht Pflicht, weil `PUT`/`DELETE` über den natürlichen Schlüssel ohnehin idempotent sind und `HandleApiIdempotency::fallbackReference()` auf den Nutzlast-Hash zurückfällt. Diese Entscheidung im Test als Kommentar festhalten, damit sie nicht als Versehen gelesen wird.

---

## Phase 3: Implementation (Track B)

- [x] T110 [US1] `plugins/accountant-api/src/CoreBridge/BudgetBridge.php` — die einzige Modulklasse, die `App\Domains\Accounting\*` importiert: `Budget`, `AccountCodeResolver`, `UpsertBudgetAction`, `DeleteBudgetAction`. Kapselt Kontocode-Auflösung, Abfrage, Berechtigungsprüfung (`$user->can(...)`) und die Abbildung Kern-Exception → stabiler Fehlercode, analog zu `JournalCorrectionBridge::errorCodeFor()`.
- [x] T111 [US1] `plugins/accountant-api/src/Resources/BudgetResource.php` — `account_code`, `account_name`, `fiscal_year`, `monthly_amount` als Dezimalstring, `annual_amount` als abgeleiteter Wert, `updated_at`. Kein `@mixin` und kein Import des Kernmodells, Felder über dynamischen Zugriff lesen (Grenzregel wie T048).
- [x] T112 [US2] `plugins/accountant-api/src/Requests/PutBudgetRequest.php` — nur `monthly_amount` (`required`, `numeric`, `min:0`, `max:99999999.99`); `account_code` und `fiscal_year` kommen aus dem Pfad und werden dort validiert. Die Prüfungen aus `StoreBudgetRequest` (Konto gehört der Organisation, Konto ist aktiv) nachbilden — der Grenztest verbietet den Import der Kern-Request, die Regeln sind also bewusst an zwei Orten zu pflegen. Beide Stellen wechselseitig im Docblock verweisen.
- [x] T113 [US1] [US2] `plugins/accountant-api/src/Controllers/BudgetController.php` — `index`, `show`, `put`, `destroy`, alles über `BudgetBridge`. Routenparameter sind einfache Strings, keine impliziten Modellbindungen (Grenzregel wie T046).
- [x] T114 [US1] [US2] `plugins/accountant-api/routes/api.php` — die vier Routen unter `Route::prefix('api/v1')` (nicht `v1`, siehe den in T043 gefundenen Fehler) mit Namensraum `api.accountant-api.budgets.*` und der Middleware-Kette `['auth:sanctum', 'api-org', 'feature:api_access', 'feature:budgets', 'throttle:api']`. `{fiscal_year}` per `->whereNumber()` einschränken, damit `/budgets/{code}/{year}` nicht mit künftigen Unterpfaden kollidiert.
- [x] T115 [US2] `plugins/accountant-api/src/AccountantApiServiceProvider.php` — `AbilityCatalog::register(Budget::class, ...)` für `viewAny`/`view` → `AccountingView`, `create` → `AccountingCreate`, `update` → `AccountingEdit`, `delete` → `AccountingDelete`. `TokenPermissionMap::get()` ist nach Modellklasse geschlüsselt und kennt `Budget` nicht; ohne diese Registrierung kann ein Organisationstoken die Endpunkte nicht nutzen. Der Import des Kernmodells ist hier zulässig, der Grenztest nimmt den ServiceProvider ausdrücklich aus.
- [x] T116 [US1] [US2] `plugins/accountant-api/contract.json` um die vier Operationen ergänzen; `ModuleContractReconciliationTest` muss sie kollisionsfrei gegen `contract/api-contract.json` abgleichen. Die Kerndatei bleibt unberührt (Entwurf aus T020).

**Gate erfüllt (2026-09-14)**: `plugins/accountant-api/tests` 42/42 (18 bestehende, 24 neue), `tests/Feature/Plugins` 13/13, `tests/Feature/Api` 82/82, `tests/Security` 143/143 (6 vorbestehende Skips), `tests/Unit/Accounting` 52/52, `BudgetFlowTest` 9/9. Pint sauber, PHPStan projektweit ohne Fehler.

**Zwei Befunde, die den Entwurf geändert haben**:

1. **Die Modulroutengruppe erbt keine Middleware.** `loadRoutesFrom()` übernimmt nichts vom Kernstack; die bestehende Korrekturgruppe zählt ihn explizit auf und liess dabei `HandleApiIdempotency` **und** `LogOrgTokenActivity` weg. Bei den Korrekturen fiel das nie auf, weil deren Controller die Idempotenz selbst führt — das Aktivitätsprotokoll für Organisationstokens fehlt dort aber bis heute. **Vorbestehende Lücke, hier nicht angefasst**, weil sie ausgelieferte Endpunkte im Verhalten ändern würde; die Budgetgruppe führt `LogOrgTokenActivity` von Anfang an mit. Der irreführende Kopfkommentar in `routes/api.php` des Moduls ist berichtigt.

2. **Der automatische Idempotenz-Rückfall ist für Modulrouten unbrauchbar.** `ApiIdempotencyService::reserve()` bildet den Schlüssel aus dem Routen*namen* plus einem Nutzlast-Hash; die konkreten Pfadparameter gehen weder in den Schlüssel noch in den Hash ein. `HandleApiIdempotency::fallbackReference()` rettet das für Kernrouten nur deshalb, weil deren Parameter implizite Modellbindungen sind und die Methode daraus eine Modell-ID zieht. Modulrouten verwenden bewusst reine Strings (Grenzregel), also fällt der Schlüssel auf den Rumpf-Hash zurück — `PUT /budgets/3000/2026` und `PUT /budgets/6000/2026` mit demselben Betrag teilten sich einen Schlüssel, und der zweite Aufruf hätte den ersten wiedergegeben statt zu schreiben. Ein zweites `DELETE` hätte `204` wiedergegeben, ohne zu löschen. **Folge**: die Budgetgruppe führt `HandleApiIdempotency` nicht, Budgets sind durch den natürlichen Schlüssel ohnehin idempotent. Die vier Tests in `BudgetIdempotencyTest` nageln genau diese Fälle fest. Der Kernfehler selbst ist **nicht** behoben — er trifft heute keine Kernroute, und `fallbackReference()` zu ändern hieße, das Verhalten jeder bestehenden Mutation anzufassen. Als offener Punkt notiert.

**Weitere Abweichungen**:

- **T108** liegt in `tests/Feature/` statt in `tests/Security/`, wie schon bei T041 entschieden.
- **T112**: Der ursprüngliche Docblock verwies per `@see` auf den vollqualifizierten Namen der Kern-Request. Pint wandelte das in einen echten `use`-Import um — und verletzte damit genau den Grenztest, den der Kommentar erklärte. Der Hinweis steht jetzt als Fliesstext, mit einer Notiz, warum er das bleiben muss.
- **T113**: `BudgetPolicy` trennt `create` und `update`. Der Upsert prüft deshalb vorab, ob die Zeile existiert, und autorisiert die tatsächlich ausgeführte der beiden Aktionen; daraus ergibt sich auch `201` gegen `200`.
- **PHPStan** deckt `plugins/` nicht ab (`phpstan.neon` listet nur `app/`). Das gilt schon für den bestehenden Modulcode und wurde hier nicht ausgeweitet.

---

## Phase 4: Politur und Nachweis

- [x] T117 Gezielte Testläufe: `plugins/accountant-api/tests`, `tests/Feature/Plugins`, die Budget-Webtests und `tests/Unit/Api`. Gezielte Dateien statt breiter `--filter`-Läufe (die in dieser Umgebung blockieren) und gegen eine eigene, danach verworfene Testdatenbank, damit parallele Sitzungen nicht gestört werden (Vorgehen wie T060).
- [x] T118 Pint und PHPStan auf jeder berührten Datei; Frontend-Build nur nötig, falls T105 eine Navigationsanpassung nach sich zieht.
- [x] T119 `plugins/accountant-api/README.md` um die Budget-Endpunkte, den Entscheid zum natürlichen Schlüssel und die bewusst optionale Idempotenz erweitern.
- [x] T120 `plan.md` → `## Umsetzungsstand`: Etappe 8 von „offen" auf „teilweise — Fokuspaket Budgets geliefert" setzen und diese Datei verlinken.
- [ ] T121 Nachweis gegen eine laufende Anwendung statt nur gegen die Testsuite: für eine Wegwerf-Organisation ein Budget per `PUT` setzen, in der Weboberfläche unter Buchhaltung → Budgets gegenprüfen, die Erfolgsrechnung mit Budgetspalte öffnen, per `DELETE` aufräumen und die Wegwerfdaten löschen (Vorgehen wie T064).

---

## Dependencies & Execution Order

- **Phase 1 (Track A)**: ohne Vorbedingung. T101/T102 vor T103. T104 und T105 sind unabhängig voneinander und von T101–T103.
- **Phase 2 (Track B)**: hängt an nichts, kann sofort geschrieben werden; die Tests sind bis Phase 3 rot.
- **Phase 3 (Track B)**: hängt an Phase 1 (T101, T102 für den Bridge, T104 für die Idempotenz) und an Etappe 0, die bereits steht.
- **Phase 4**: hängt an Phase 3.
- T115 blockiert jeden Tokenzugriff und sollte vor dem ersten grünen Lauf von T108 sitzen.

### Vorgeschlagene Reihenfolge

1. T105 (kleinster eigenständiger Nutzen, schliesst eine vorgefundene Lücke)
2. T101–T103 (Kern-Seam, unabhängig testbar, upstream-fähig)
3. T104 (mit Regressionstest, bevor neue Routen davon abhängen)
4. T106–T109 (Tests First)
5. T110–T116 (Modul)
6. T117–T121

Track A (T101–T105) und Track B (T106–T116) landen nie im selben Commit, wie
plan.md unter `Liefer- und Git-Strategie` verlangt.

---

## Commit- und Rollback-Strategie

**Commits**: Ein Commit pro Task oder pro eng zusammengehöriger Gruppe
(T101+T102 zusammen, T110+T111 zusammen), nie ein Sammelcommit über eine Phase.
Jeder Commit für sich lauffähig, Tests grün, Pint und PHPStan sauber.

**Rollback**: Dieses Paket enthält **keine Migration** — der grösste Unterschied
zum Korrektur-Fokuspaket. Ein Rollback ist deshalb auf drei Ebenen trivial:

1. **Task-Ebene**: `git revert` des einzelnen Commits.
2. **Modul-Ebene**: Die Deployment-Allow-List aus T008 schaltet das ganze Modul
   ab, ohne dass Daten verloren gehen. Bereits gesetzte Budgets bleiben über die
   Weboberfläche vollständig nutzbar.
3. **Feature-Ebene**: `feature:budgets` schaltet nach T105 Web und API gemeinsam
   ab, ohne Deployment.

Track A bleibt auch bei abgeschaltetem Modul sinnvoll: T101–T103 sind eine reine
Refaktorierung, T104 eine Korrektur an einer zu breiten Regel, T105 schliesst
eine Lücke im Kern.
