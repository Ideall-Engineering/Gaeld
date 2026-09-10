# Gäld – Plan für geführte Journal-Korrekturen mit API

**Stand:** 10. September 2026  
**Ziel:** Fehler in bereits verbuchten Journalbuchungen einfach korrigieren, ohne die Nachvollziehbarkeit und Unveränderlichkeit des Hauptbuchs aufzugeben.

> **Umsetzungsstatus (10. September 2026):** Vollständig umgesetzt — Domain-Kern,
> Weboberfläche und API-Modul. Details, offene Punkte und der vollständige
> Task-Verlauf stehen in `specs/007-accountant-api/tasks.md` im Repository
> (Phasen 1–5; Phase 6 „Politur" läuft noch). Diese Datei bleibt als
> ursprüngliches Konzeptdokument unverändert stehen.

## Zielbild

Eine verbuchte Originalbuchung bleibt unverändert. Gäld führt Benutzer stattdessen durch einen zusammengehörigen Korrekturvorgang:

1. Originalbuchung auswählen und `Korrigieren` anklicken.
2. Eine Begründung und das Korrekturdatum angeben.
3. Gäld erzeugt einen nicht editierbaren Gegenbuchungsentwurf und eine editierbare Kopie als Ersatzbuchung.
4. Die Ersatzbuchung kontrollieren und korrigieren.
5. Gegenbuchung und Ersatzbuchung gemeinsam verbuchen.

Erst der letzte Schritt wirkt sich auf Hauptbuch, Kontosalden und MWST aus. Schlägt ein Teil fehl, wird keine der beiden Buchungen wirksam.

## Fachliche Regeln

- Das Original wird niemals verändert oder gelöscht.
- Gegenbuchung und Ersatzbuchung gehören dauerhaft zu einem Korrekturvorgang.
- Der Gegenbuchungsentwurf kann nicht bearbeitet werden.
- Die beiden Entwürfe können weder einzeln verbucht noch einzeln gelöscht werden.
- Ein offener Korrekturvorgang kann vollständig verworfen werden.
- Eine verbuchte Korrektur kann nicht rückgängig gelöscht werden. Eine weitere Korrektur beginnt bei der letzten Ersatzbuchung.
- Eine Buchung kann nicht gleichzeitig mehrfach korrigiert oder storniert werden.
- Das Korrekturdatum wird ausdrücklich angezeigt und bestätigt; Gäld verschiebt es nicht stillschweigend.
- Bei offenem Geschäftsjahr und offener MWST-Periode wird das ursprüngliche Buchungsdatum vorgeschlagen.
- Ist die ursprüngliche Periode gesperrt, wird ein Datum in einer offenen Periode vorgeschlagen und die Periodenwirkung deutlich angezeigt.

## Abgrenzung nach Herkunft

Die neue Funktion ist zunächst für folgende Buchungen vorgesehen:

- manuell erfasste Journalbuchungen;
- über die API erfasste Journalbuchungen;
- aus einer Migration, beispielsweise Banana, importierte Journalbuchungen.

Buchungen aus Fachmodulen werden über die dortigen Abläufe korrigiert, damit Belegstatus und Hauptbuch nicht auseinanderlaufen. Dazu gehören insbesondere:

- Rechnungen und Zahlungen;
- Ausgaben;
- Bankabstimmungen;
- Lohnbuchungen;
- Anlagen und Abschreibungen;
- MWST-Abschlüsse;
- Jahresabschlussbuchungen.

Gäld muss diese Herkunft anhand der tatsächlichen Verknüpfungen prüfen. Das heutige Feld `journal_entries.type` reicht dafür nicht zuverlässig aus.

## Technische Architektur

Die Korrektur wird als Fähigkeit der Accounting-Domain umgesetzt. Weboberfläche und API verwenden dieselben Domain-Actions:

- `PrepareJournalCorrectionAction`
- `PostJournalCorrectionAction`
- `CancelJournalCorrectionAction`
- `UpdateJournalDraftAction` für die sichere Bearbeitung des Ersatzentwurfs

Controller übernehmen nur Authentisierung, Autorisierung, Eingabevalidierung und Darstellung. Sie erzeugen oder verändern keine Buchungszeilen selbst.

### Datenmodell

Eine additive Kerntabelle `journal_corrections` enthält:

- UUID und Organisation;
- Originalbuchung;
- Gegenbuchungsentwurf beziehungsweise verbuchte Gegenbuchung;
- Ersatzentwurf beziehungsweise verbuchte Ersatzbuchung;
- Status `draft` oder `posted`;
- Korrekturbegründung;
- Quelle `web` oder `api`;
- Benutzer- und Tokenkontext;
- Client-Operations-ID und Request-Hash für Idempotenz;
- Verbuchungszeitpunkt und Timestamps.

Die Journalbeziehungen werden indiziert, eindeutig und mit geschützten Fremdschlüsseln angelegt. Zusätzlich erhält eine neue Gegenbuchung ein strukturiertes `reversal_of_entry_id`. Das bisherige Präfix `REV-` bleibt lesbar, ist aber nicht mehr die fachliche Verknüpfung.

Die Organisation muss für Korrektur und alle drei Journalbuchungen übereinstimmen. Dies wird in den Actions geprüft und soweit möglich durch zusammengesetzte Fremdschlüssel abgesichert.

### Bestehende Stornos

- Bestehende Buchungen und Stornos bleiben unverändert gültig.
- Eindeutige Beziehungen aus `journal_events.payload` können einmalig nachgeführt werden.
- Aus einem ähnlichen oder mit `REV-` beginnenden Referenztext allein wird keine Beziehung abgeleitet.
- Das bestehende reine Storno verwendet nach der Umstellung ebenfalls die strukturierte Beziehung.

## API-Vertrag

Die Korrektur-API wird von Beginn an gemeinsam mit der Webfunktion bereitgestellt:

```text
POST   /api/v1/journal-entries/{original}/corrections
GET    /api/v1/journal-corrections/{correction}
PUT    /api/v1/journal-corrections/{correction}/replacement
POST   /api/v1/journal-corrections/{correction}/post
DELETE /api/v1/journal-corrections/{correction}
```

### Korrektur vorbereiten

`POST /api/v1/journal-entries/{original}/corrections`

- verlangt `reason` und `correction_date`;
- kann optional eine vollständige Ersatzbuchung im vorhandenen expliziten oder MWST-Kurzformat entgegennehmen;
- kopiert ohne Ersatzpayload sämtliche unterstützten Felder des Originals;
- liefert `201 Created` mit Korrektur, Original, Gegenbuchungsentwurf und Ersatzentwurf.

### Ersatzbuchung bearbeiten

`PUT /api/v1/journal-corrections/{correction}/replacement`

- verändert ausschließlich den Ersatzentwurf;
- verwendet dieselben Konten-, Betrags- und MWST-Regeln wie die Journalerstellung;
- liefert `200 OK` mit dem aktualisierten Korrekturvorgang.

### Korrektur verbuchen

`POST /api/v1/journal-corrections/{correction}/post`

- sperrt Original, Korrektur und beide Entwürfe gegen Parallelzugriffe;
- prüft beide Buchungen vollständig;
- verbucht Gegenbuchung und Ersatzbuchung in einer Datenbanktransaktion;
- liefert `200 OK` mit den drei Buchungen und deren Beziehungen.

### Korrektur verwerfen

`DELETE /api/v1/journal-corrections/{correction}`

- ist nur im Status `draft` zulässig;
- entfernt beide Entwürfe und den offenen Vorgang atomar;
- liefert `204 No Content`;
- hinterlässt einen Audit-Nachweis.

### Sicherheit und Idempotenz

- Alle Mutationen verlangen einen `Idempotency-Key`.
- Korrektur und Client-Operations-ID werden gemeinsam mit der fachlichen Mutation gespeichert.
- Eine Wiederholung nach einem Timeout liefert das bereits erzeugte Ergebnis und erzeugt keine Duplikate.
- Derselbe Schlüssel mit einem anderen Payload liefert einen Konflikt.
- Die neue Policy-Ability `correct` verlangt `AccountingEdit`.
- Token-Scope, Benutzerrecht, Organisation und Buchungsstatus müssen gleichzeitig passen.
- Organisationsfremde UUIDs liefern `404`, ohne die Existenz fremder Daten zu verraten.

Vorgesehene Konfliktcodes:

- `journal_entry_already_corrected`
- `journal_entry_already_reversed`
- `journal_correction_state_conflict`
- `journal_correction_concurrent_transition`
- `source_managed_entry`
- `idempotency_conflict`

Nach erfolgreicher Verbuchung wird `journal_entry.corrected` erst nach dem Datenbank-Commit veröffentlicht. Das Ereignis enthält eine eindeutige Ereignis-ID sowie die UUIDs von Original, Gegenbuchung und Ersatzbuchung.

## Weboberfläche

- Zulässige verbuchte Buchungen erhalten die Aktion `Korrigieren`.
- Das bisherige `Stornieren` bleibt für eine bewusst reine Gegenbuchung verfügbar.
- Der Korrekturdialog zeigt Original, gesperrte Gegenbuchung und editierbare Ersatzbuchung zusammen an.
- Begründung und Korrekturdatum sind Pflichtfelder.
- Nach dem Vorbereiten öffnet Gäld direkt den Ersatzentwurf.
- Die Schaltfläche `Korrektur verbuchen` macht ausdrücklich klar, dass beide Buchungen gemeinsam wirksam werden.
- Journal und Detailansicht kennzeichnen Original als `korrigiert` sowie die beiden neuen Einträge als `Gegenbuchung` und `Ersatzbuchung`.
- Alle drei Einträge sind gegenseitig verlinkt.
- Per API erzeugte Korrekturen erscheinen nach dem Neuladen identisch in der Weboberfläche.

## Prüfungen beim Verbuchen

Vor einer Verbuchung prüft Gäld mindestens:

- Organisation und Berechtigung;
- Zustand von Original und Korrektur;
- gleichzeitige Korrektur- oder Stornoversuche;
- offenes Geschäftsjahr;
- MWST-Periodensperre am Korrekturdatum;
- gültige und aktive Konten;
- eindeutige und begrenzte Referenzen;
- ausgeglichene Soll-/Haben-Summen;
- vollständige MWST-Felder;
- zulässige Herkunft der Originalbuchung.

## Testplan

### Domain- und Integrationstests

- Original bleibt in allen Fällen unverändert.
- Kopf-, Zeilen- und MWST-Felder werden vollständig kopiert.
- Gegenbuchung vertauscht Soll und Haben korrekt.
- Gegenbuchung und Ersatzbuchung wirken gemeinsam auf Ledger und MWST.
- Fehler beim zweiten Eintrag rollt den gesamten Verbuchungsvorgang zurück.
- Verknüpfte Entwürfe können nicht einzeln geändert, verbucht oder gelöscht werden.
- Eine zweite Korrektur desselben Originals wird verhindert.
- Eine weitere Korrektur der Ersatzbuchung ist möglich.
- Reine Stornos erhalten ebenfalls eine strukturierte Verknüpfung.

### API- und Sicherheitstests

- Prepare, Get, Update, Post und Delete;
- explizite Buchungszeilen und MWST-Kurzform;
- Idempotenz-Replay und Konflikt bei geändertem Payload;
- Prozessabbruch nach fachlichem Commit;
- parallele Korrekturaufrufe;
- Benutzer- und Tokenrechte;
- organisationsfremde Zugriffe;
- Null-, Lang- und Doppelreferenzen;
- stabile Status- und Fehlercodes.

### Perioden- und Herkunftstests

- offene Periode;
- geschlossenes Geschäftsjahr;
- gesperrte MWST-Periode;
- Korrektur in einer späteren offenen Periode;
- archiviertes Original ohne Veränderung des Archivs;
- Ablehnung von Buchungen aus jedem angebundenen Fachmodul.

### Frontendtests

- Korrekturdialog und Pflichtfelder;
- Bearbeitung des Ersatzentwurfs;
- gemeinsame Verbuchung;
- vollständiger Abbruch;
- Fehleranzeige;
- Aktionssperren;
- sichtbare Verknüpfung nach einer API-Korrektur.

## Umsetzungsschritte

1. Fachliche Migrationen und Modelle für Korrekturen und strukturierte Gegenbuchungen.
2. Eligibility-Service für manuelle und quellverwaltete Buchungen.
3. MWST-sichere `UpdateJournalDraftAction` und vollständige Kopierlogik.
4. Prepare-, Post- und Cancel-Actions mit Transaktionen und Sperren.
5. Policy-, Audit-, Ereignis- und Cache-Integration.
6. Webrouten und Korrekturdialog.
7. Accountant-API-Routen, Requests, Resources und Idempotenz.
8. API-Vertrag und Dokumentation.
9. Fokussierte Domain-, API-, Sicherheits- und Frontendtests.
10. Pint, PHPStan, Frontend-Build, Gesamttests und Pilot-Smoke.

## Aufwand und Abhängigkeiten

Geschätzter Aufwand für Weboberfläche und API zusammen: **7–10 Arbeitstage** einschließlich Migration, Domain-Actions, UI, API-Vertrag und Tests.

Voraussetzung ist das allgemeine Modul-, Sicherheits- und Vertragsfundament des Accountant-API-Moduls. Falls dieses noch nicht vorhanden ist, kommen ungefähr **5–8 Arbeitstage** hinzu. Gesamt ab dem aktuellen Planungsstand: **12–18 Arbeitstage**.

## Abnahmekriterien

Die Umsetzung ist abgeschlossen, wenn:

- eine fehlerhafte manuelle, API- oder Migrationsbuchung über Web und API korrigiert werden kann;
- das Original unverändert und dauerhaft sichtbar bleibt;
- Gegenbuchung und Ersatzbuchung ausschließlich gemeinsam wirksam werden;
- Salden und MWST nach der Korrektur fachlich stimmen;
- Wiederholungen und Parallelaufrufe keine Doppel- oder Halbbuchungen erzeugen;
- Herkunftsbuchungen nur über ihr zuständiges Fachmodul korrigiert werden;
- der vollständige Zusammenhang in UI, API, Audit-Log und Webhook sichtbar ist;
- alle fokussierten Tests, PHPStan, Pint und der Frontend-Build erfolgreich sind.
