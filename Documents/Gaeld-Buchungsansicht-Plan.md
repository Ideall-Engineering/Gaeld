# Gäld — Banana-Zeilenansicht und Suche für die Buchungen

Stand: 10.09.2026 · Branch `deployment/v3.8.6-ideall` · Merge-Base zu Upstream `3a92e1c2`

## 1. Ausgangslage

Die Buchungsseite (`/accounting/journal-entries`) ist die einzige grosse Listenseite
in Gäld ohne Suche, Filter und Sortierung. Alle übrigen — Kontakte, Rechnungen,
Spesen, Anlagen, Mitarbeitende, Budgets — nutzen das Hausmuster aus
`App\Support\QueryBuilder` plus einer `Queries/<X>Query.php` je Domain. Für
Buchungen fehlt diese Klasse; der Controller lädt stumpf:

```php
// app/Domains/Accounting/Controllers/AccountingController.php:74
$entries = JournalEntry::with('lines.account')
    ->orderByDesc('date')
    ->paginate(config('accounting.pagination.default'));
```

Gleichzeitig zeigt die Tabelle pro Beleg nur Datum, Beleg, Beschreibung und
Status. Konten, Beträge und die MWST-Angaben liegen hinter dem Aufklapp-Slot
(`JournalEntries.vue:239-266`) — bei der Nacharbeit eines Banana-Journals ist das
ein Klick pro Beleg.

**Ziel:** die gewohnte Banana-Zeilenansicht als Standardansicht, mit Suche und
Betragsfilter, ohne die Belegansicht zu verlieren.

## 2. Entscheidungen

| Frage | Entscheid |
|---|---|
| Ansichten | Umschalter, **Banana ist Standard**, Wahl pro Browser gemerkt |
| Upstream-PR | **Nein**, fork-lokal |
| Suche | Beleg, Beschreibung, Zeilentext, Kontonummer und Kontoname |
| Filter | Betrag von/bis |
| Nicht im Umfang | Datumsbereichsfilter, MWST-Code-/Status-Dropdown, Inline-Editieren |

## 3. Zielbild

Das Zeilenformat ist im Repo bereits festgelegt — durch die Fixture des
bestehenden Banana-Importers, `tests/fixtures/banana-journal-2026.tsv`:

```
Datum | Beleg | Beschreibung | KontoSoll | KontoHaben | Betrag | MwSt-Code | Satz | BetragOhneMwSt | MwStBetrag
```

Die Ansicht ist dieses Importformat rückwärts gelesen:

```
[ Banana ] [ Belege ]     🔍 Suche…      Betrag von [    ] bis [    ]

Datum     Beleg  Beschreibung      Soll  Haben  Betrag     MwSt  Satz   Netto   MwSt-Betr.
05.01.26  1069   Google Cloud      6034  1020       0.76   M81   8.10    0.70      0.06
07.01.26  1001   Gutschrift 10047  1020  3100     259.44   V81  -8.10  240.00    -19.44
09.01.26  1002   Lohn Januar       5000  1020   4'250.00    —      —       —         —
                 ↳ Sozialabzüge    5700  2270     340.00    —      —       —         —
```

**Ableitungsregel.** Ein Beleg mit genau zwei Zeilen wird zu **einer** Bildschirmzeile:
Soll ist das Konto der Zeile mit `debit > 0`, Haben das der Zeile mit `credit > 0`,
Betrag ist der gemeinsame Betrag. Ein Beleg mit mehr als zwei Zeilen (deine
Lohnbuchungen) wird zu n Zeilen: die erste trägt Datum, Beleg und Beschreibung,
die weiteren sind eingerückte Fortsetzungszeilen mit je nur einer gefüllten
Kontoseite. Genau so stellt Banana Sammelbuchungen dar.

**Paginiert wird über `journal_entries`, nicht über die Zeilen.** Sonst zerreisst
eine Seitengrenze einen Beleg. Sichtbare Folge: die Seitengrösse zählt Belege,
nicht Zeilen.

Die Ableitung passiert **im Frontend** als `computed` über `entries.data`. Die
Zeilen sind ohnehin geladen; das hält die Backend-Änderung klein — und damit die
Konfliktfläche gegenüber Upstream.

## 4. Merge-Disziplin

Der Fork ist 18 Commits und 12'796 Zeilen von Upstream entfernt, hat aber die hier
betroffenen Dateien **noch nie angefasst**. Upstream hat sie seit dem Merge-Base
ebenfalls nicht angefasst (0 Commits an `JournalEntries.vue`,
`AccountingController.php` und `DataTable.vue`). Wir eröffnen also eine neue
Konfliktfläche und halten sie bewusst klein:

1. **Neue Dateien statt Änderungen**, wo immer möglich. Alles Neue landet in
   `app/Domains/Accounting/Queries/` und `resources/js/Components/Accounting/`
   (letzteres Verzeichnis existiert noch nicht — es kann nie konfligieren).
2. **`app/Support/QueryBuilder.php` und `resources/js/Components/UI/DataTable.vue`
   bleiben unberührt.** Das sind geteilte Dateien; ein Konflikt dort trifft alle
   Listenseiten gleichzeitig. Konkreter Fallstrick: `QueryBuilder::searchable()`
   löst nur *eine* Relationsebene auf (`applyDatabaseSearch()` macht
   `explode('.', $column, 2)`), also funktioniert `lines.description`, aber
   `lines.account.code` nicht. Die Versuchung ist, den QueryBuilder zu erweitern.
   Stattdessen: eigene Suchklausel in `JournalEntryQuery` (siehe 5.1).
3. **Die zwei unvermeidlichen Änderungen lokal halten** — ein Hunk im Controller,
   zwei im Vue-Page.

## 5. Umsetzung

### 5.1 `app/Domains/Accounting/Queries/JournalEntryQuery.php` — neu

Nach dem Muster von `app/Domains/Expenses/Queries/ExpenseQuery.php`.

```php
public static function list(Request $request, int $perPage): LengthAwarePaginator
```

- Basis: `JournalEntry::with(['lines.account', 'lines.vatRate'])`
  — `lines.vatRate` ist neu und liefert `code` und `rate` für die MWST-Spalten.
- **Sortierung** über `QueryBuilder::for(...)->allowedSorts(['date', 'reference',
  'created_at'], 'date', 'desc')->apply()`. Danach am zurückgegebenen Builder ein
  zusätzliches `->orderBy('reference')` anhängen: heute ist die Reihenfolge bei
  gleichem Datum unbestimmt, was in einer Zeilenansicht sofort auffällt.
- **Suche** als eigene private Methode, nicht über `->searchable()`, wegen der
  Zweiebenen-Relation. Eine Klausel, alles `orWhere`:
  `reference`, `description`, `lines.description` (via `orWhereHas`),
  sowie `lines.account.code` und `lines.account.name` (via verschachteltem
  `orWhereHas('lines', fn ($q) => $q->whereHas('account', ...))`).
  Operator `ilike` unter PostgreSQL, sonst `like` — die Produktion und die CI
  laufen auf Postgres 16. Die kleine Duplikation von
  `QueryBuilder::likeOperator()` ist der bewusst gezahlte Preis dafür, die
  geteilte Datei nicht anzufassen (siehe 4.2).
- **Betragsfilter** `filter.amount_min` / `filter.amount_max` als
  `whereHas('lines', fn ($q) => $q->where('debit', '>=' / '<=', …))`.
  Bereichsfilter statt Textsuche, weil `LIKE` auf `decimal(…,2)` unzuverlässig ist.
- Abschluss mit `->paginate($perPage)->withQueryString()`.

### 5.2 `AccountingController::journalEntries()` — ein Hunk

Die drei Query-Zeilen durch `JournalEntryQuery::list($request, config('accounting.pagination.default'))`
ersetzen und die `query`-Prop mitgeben, exakt in der Form, die
`InvoiceController::index()` (Zeile 48-61) verwendet:

```php
'query' => [
    'sort'      => $request->input('sort', 'date'),
    'direction' => $request->input('direction', 'desc'),
    'search'    => $request->input('search', ''),
    'filter'    => $request->input('filter', []),
],
```

`$accounts` und `can` bleiben unverändert.

### 5.3 `resources/js/Components/Accounting/` — neu

- **`JournalToolbar.vue`** — Suchfeld (300 ms entprellt, wie `DataTable.vue:111-116`),
  Betrag von/bis, und der Ansichts-Umschalter. Steht über beiden Ansichten, damit
  Suche und Filter in beiden identisch sind. Emittiert `search`, `filter`, `view`.
- **`JournalBananaTable.vue`** — reine Darstellung. Nimmt `entries.data`, wendet
  die Ableitungsregel aus §3 an, rendert auf Basis des Primitivs
  `Components/UI/Table.vue` und darunter die Laravel-Pagination-Links aus
  `entries.links`. Beträge über `useFormatters().formatCurrency`, Datum über
  `formatDate`. Mobil: horizontal scrollbar statt Kartenansicht — zehn Spalten
  ergeben keine sinnvolle Karte.

Ein Segmented Control existiert in `Components/UI` noch nicht; er entsteht als
Teil von `JournalToolbar.vue` und bleibt damit fork-lokal.

### 5.4 `resources/js/Pages/Accounting/JournalEntries.vue` — zwei Hunks

- Skript: `query`-Prop ergänzen, `useEntityIndexQuery` aus
  `@/lib/useEntityIndexTable` einbinden (liefert `handleSort`, `handleSearch`,
  `handleFilter` fertig), `view`-Ref mit `localStorage`-Persistenz
  (Schlüssel `gaeld.journal.view`, Vorgabe `banana`), `sortable: true` an den
  Spalten `date` und `reference`.
- Template: `<JournalToolbar>` über die Karte, dann `v-if` auf die Ansicht —
  `<JournalBananaTable>` oder der bestehende `<DataTable … expandable>`-Block,
  letzterer zusätzlich mit `:sort`, `:direction` und `@sort`.

Der Erstell-/Bearbeiten-Dialog (Zeile 271-425) bleibt unberührt.

### 5.5 Übersetzungen

Neue Schlüssel in **allen vier** `lang/{de,en,fr,it}/app.php`:
`journal_view_banana`, `journal_view_entries`, `search_journal_entries`,
`amount_from`, `amount_to`, `debit_account`, `credit_account`, `vat_code`,
`vat_rate`, `net_amount`. Ein generischer `search`-Schlüssel existiert bereits
(`lang/en/app.php:141`).

Es gibt **kein CI-Gate für Übersetzungsvollständigkeit** — das ist reine Disziplin.
Vor dem Commit prüfen, dass alle vier Dateien dieselbe Schlüsselmenge haben.

## 6. Verifikation

**Feature-Test, neu:** `tests/Feature/Accounting/JournalEntryIndexTest.php`.
Es gibt heute **keinen einzigen Test** auf `GET /accounting/journal-entries` —
`ManualJournalEntryTest.php` deckt nur Create, Update, Post und Reverse ab.
Abzudecken, jeweils über `assertInertia`:

- Grundlast: Seite rendert, `entries.data` gefüllt, `query`-Prop vorhanden.
- Suche nach Belegnummer, nach Beschreibungsfragment, nach Kontonummer (`6034`)
  und nach Kontoname — je ein Treffer, je ein Nicht-Treffer.
- Betragsfilter `amount_min`/`amount_max` grenzt korrekt ein.
- Sortierung nach `date` und `reference`, beide Richtungen; unerlaubte
  Sortierspalte fällt auf `date desc` zurück (`QueryBuilder::applySorting()`).
- **Mandantentrennung:** Buchung einer Fremdorganisation taucht bei keiner
  Suchvariante auf. Das ist der wichtigste Fall — die Suche greift neu über
  `whereHas` auf `accounts`, und die Organisationsgrenze muss dort halten.

**Manuell**, gegen das importierte Banana-Journal 2026 (Belege 1001–1113):

1. Banana-Ansicht ist nach dem ersten Aufruf voreingestellt; Umschalten und
   Neuladen behält die Wahl.
2. Beleg 1069 (Google Cloud) erscheint als eine Zeile mit `6034 / 1020 / 0.76 /
   M81 / 8.10 / 0.70 / 0.06` — abgleichbar mit
   `tests/fixtures/banana-journal-2026.tsv`.
3. Eine Lohnbuchung erscheint als Sammelbeleg mit Fortsetzungszeilen und wird
   von keiner Seitengrenze zerrissen.
4. Suche nach `1020` findet Belege über das Konto, nicht über die Belegnummer.
5. Beide Ansichten zeigen bei gleicher Suche dieselbe Belegmenge.

**Gates:** `composer lint` (Pint), `composer stan` (PHPStan Level 7), die
Testgruppen Feature/Unit/Security, `pnpm build`. Der `.githooks/pre-commit` führt
Pint und PHPStan ohnehin aus.

## 7. Aufwand

| Teil | Aufwand |
|---|---|
| `JournalEntryQuery.php` inkl. Zweiebenen-Suche und Betragsfilter | 0,5 T |
| Controller-Anpassung | 0,25 T |
| `JournalToolbar.vue` + `JournalBananaTable.vue` + Umschalter | 1 T |
| Ableitungsregel Soll/Haben/Betrag/MWST | 0,25 T |
| Übersetzungen de/en/fr/it | 0,25 T |
| `JournalEntryIndexTest.php` | 0,5 T |
| **Total** | **≈ 3 Personentage** |

## 8. Offene Punkte

- **Kostenstellen-Spalte**, falls später gewünscht: `cost_center_id`,
  `lettrage_key`, `currency`, `exchange_rate` und `amount_local` existieren als
  DB-Spalten auf `transaction_lines`, fehlen aber im `$fillable` von
  `TransactionLine`. Lesen und Anzeigen geht, Schreiben nicht.
- **Meilisearch:** läuft `scout.driver=meilisearch`, nimmt
  `QueryBuilder::applySearch()` einen anderen Zweig. Da wir die Suche selbst
  bauen, sind wir davon nicht betroffen — die Journalsuche bleibt in jedem Fall
  eine Datenbanksuche. Bei sehr grossen Journalen ist das der Punkt, an dem
  nachzumessen ist.
- **Frontend-Tests** gibt es im Projekt nicht (kein Vitest; Playwright ist
  Dependency ohne Journal-Specs). Die Absicherung ruht auf den Feature-Tests
  plus der manuellen Abnahme aus §6.
