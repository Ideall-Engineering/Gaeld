# Feature Specification: Vollständige Buchhalter-API

**Feature Branch**: `deployment/v3.8.6-ideall`

**Created**: 2026-09-09

**Status**: Draft – modular überarbeitet

**Input**: Ein berechtigter Buchhalter soll alle von Gäld unterstützten Buchhaltungsaufgaben ausschliesslich über die API erledigen können, während der Mandant die Ergebnisse und Aktivitäten in der bestehenden Weboberfläche mitverfolgt. Die dafür neu entstehenden Erweiterungen werden als eigenständig aktivierbares Accountant-API-Modul ausgeliefert, damit spätere Aktualisierungen des Gäld-Kerns möglichst konfliktarm übernommen werden können.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Laufende Buchhaltung erledigen (Priority: P1)

Ein autorisierter Buchhalter pflegt Stammdaten, erfasst und korrigiert Belege und Journalentwürfe, bucht Geschäftsvorfälle und storniert Fehlbuchungen ausschliesslich über die API.

**Why this priority**: Ohne vollständige Tagesbuchhaltung ist die API kein eigenständiger Arbeitskanal.

**Independent Test**: Ein neuer Geschäftsfall kann vom Kontakt und Beleg bis zur verbuchten Journalbuchung über die API abgewickelt und danach in der Weboberfläche geprüft werden.

**Acceptance Scenarios**:

1. **Given** ein Buchhalter mit den benötigten Rechten, **When** er Stammdaten und einen Beleg per API anlegt und verbucht, **Then** sind Beleg, Status und Journalbuchung in API und Weboberfläche identisch sichtbar.
2. **Given** eine bereits gebuchte Transaktion, **When** eine Korrektur notwendig ist, **Then** bleibt die Originalbuchung unverändert und die Korrektur erfolgt nachvollziehbar durch eine Gegenbuchung.
3. **Given** ein wiederholter identischer Schreibaufruf, **When** die Verbindung nach der ersten Verarbeitung abbricht, **Then** erzeugt die Wiederholung keinen doppelten Geschäftsfall.

---

### User Story 2 - Bank abstimmen und Zahlungen vorbereiten (Priority: P2)

Der Buchhalter importiert Bankbewegungen, ordnet sie Rechnungen, Ausgaben, MWST oder manuellen Buchungen zu und bereitet ausgehende Zahlungen vor.

**Why this priority**: Bankabstimmung verbindet Belege und Hauptbuch und ist für eine abgeschlossene laufende Buchhaltung unverzichtbar.

**Independent Test**: Ein Kontoauszug kann importiert, jede Bewegung abgestimmt und der verbleibende offene Saldo kontrolliert werden.

**Acceptance Scenarios**:

1. **Given** ein gültiger Kontoauszug, **When** er erneut mit derselben Identität importiert wird, **Then** entstehen keine doppelten Banktransaktionen.
2. **Given** eine offene Bankbewegung, **When** sie einem Beleg zugeordnet oder manuell verbucht wird, **Then** sind Abstimmungsstatus und erzeugte Buchung in der Weboberfläche sichtbar.
3. **Given** eine falsche Abstimmung, **When** sie aufgehoben wird, **Then** werden alle Auswirkungen regelkonform korrigiert und protokolliert.

---

### User Story 3 - Reports und MWST abschliessen (Priority: P3)

Der Buchhalter ruft Erfolgsrechnung, Bilanz, Saldobilanz, Journal, Cashflow, offene Posten und MWST-Bericht ab, exportiert sie und verbucht den MWST-Abschluss.

**Why this priority**: Erst Auswertungen und MWST-Verarbeitung machen die erfassten Daten fachlich kontrollierbar und periodisch abschliessbar.

**Independent Test**: Eine Periode kann vollständig ausgewertet, mit den zugrunde liegenden Buchungen abgestimmt, exportiert und als MWST-Periode abgeschlossen werden.

**Acceptance Scenarios**:

1. **Given** gebuchte Geschäftsvorfälle, **When** ein Bericht für eine Periode abgerufen wird, **Then** stimmt er mit der Webansicht und den Journalzeilen überein.
2. **Given** ein grosser Export, **When** der Buchhalter ihn startet, **Then** kann er den Status abfragen und das fertige Ergebnis sicher herunterladen.
3. **Given** eine bereits abgeschlossene MWST-Periode, **When** eine Korrektur erfolgt, **Then** bleibt der ursprüngliche Abschluss nachvollziehbar und die Korrektur wird versioniert oder gegengebucht.

---

### User Story 4 - Geschäftsjahr abschliessen (Priority: P4)

Der Buchhalter prüft Abschlussvoraussetzungen, verbucht fehlende Abgrenzungen und Abschreibungen, schliesst das Geschäftsjahr und erzeugt das gesetzliche Archiv.

**Why this priority**: Der Jahresabschluss ist notwendig für vollständige Buchhaltungsautonomie, aber seltener als die laufenden Prozesse.

**Independent Test**: Ein vorbereitetes Geschäftsjahr kann über einen Vorabcheck geschlossen und als unveränderliches Archivpaket abgerufen werden.

**Acceptance Scenarios**:

1. **Given** offene Abschlussprobleme, **When** der Vorabcheck ausgeführt wird, **Then** werden alle Blocker konkret gemeldet und ein Abschluss verhindert.
2. **Given** eine abschlussbereite Periode, **When** ein berechtigter Buchhalter den Abschluss ausführt, **Then** entstehen Abschlussbuchung, Periodensperre, Folgeeröffnung und Archiv atomar.
3. **Given** ein geschlossenes Jahr, **When** eine Wiedereröffnung ohne das ausdrückliche Recht versucht wird, **Then** wird sie abgelehnt und protokolliert.

---

### User Story 5 - Lohn und optionale Module bearbeiten (Priority: P5)

Sofern die Module aktiviert sind, verwaltet der Buchhalter Personal und Lohnläufe sowie Anlagen, Kostenstellen, Fremdwährungen, Budgets, Steuerdeklarationen und Konsolidierung über die API.

**Why this priority**: Diese Funktionen vervollständigen die Funktionsparität, sind aber nicht bei jedem Mandanten aktiviert.

**Independent Test**: Jedes aktivierte Modul kann in einem isolierten Ende-zu-Ende-Szenario ohne Nutzung einer Web-Eingabemaske bearbeitet werden.

**Acceptance Scenarios**:

1. **Given** ein aktiviertes Lohnmodul, **When** ein Lohnlauf vorbereitet, erzeugt und gebucht wird, **Then** stimmen Lohnabrechnungen, Journal und Webansicht überein.
2. **Given** ein nicht aktiviertes Zusatzmodul, **When** dessen API aufgerufen wird, **Then** wird der Zugriff mit einem stabilen, verständlichen Fehler abgelehnt.

### Edge Cases

- Gleichzeitige Bearbeitung desselben Entwurfs über API und Weboberfläche.
- Wiederholte Aufrufe nach Timeout oder unbekanntem Verarbeitungsergebnis.
- Zugriff auf Ressourcen einer anderen Organisation.
- Buchungsversuche in geschlossenen oder archivierten Perioden.
- Teilweise verarbeitete Massenläufe, Exporte, OCR-Aufträge oder Archivjobs.
- Gelöschte oder deaktivierte Stammdaten, die in historischen Buchungen weiter referenziert werden.
- Sehr grosse Journale und Exportdateien.
- Sensible Lohn- und Personalfelder bei unzureichenden Teilrechten.
- Das Accountant-API-Modul ist deaktiviert, fehlt oder ist mit der installierten Gäld-Kernversion nicht kompatibel.
- Eine neue Kernversion ändert einen vom Modul verwendeten Vertrag oder ein beobachtbares Verhalten.
- Eine Modulroute kollidiert mit einer bestehenden oder später neu hinzukommenden Kernroute.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Das System MUST einen persönlich zugeordneten, organisationsgebundenen API-Zugang mit getrennten Lese-, Schreib-, Buchungs-, Abschluss- und Modulrechten bereitstellen.
- **FR-002**: Der Buchhalter MUST Organisationseinstellungen, Geschäftsjahre, Kontenplan, MWST-Sätze, Kategorien, Kontakte und Bankkonten im für seine Aufgaben erforderlichen Umfang lesen und verwalten können.
- **FR-003**: Der Buchhalter MUST Ausgangsrechnungen, Gutschriften, Zahlungen, Mahnungen, Eingangsbelege und zugehörige Dateien durch ihren vollständigen Lebenszyklus bearbeiten können.
- **FR-004**: Der Buchhalter MUST Journalentwürfe erstellen und ändern sowie Buchungen buchen, stornieren, filtern und exportieren können.
- **FR-005**: Das System MUST gebuchte, abgeschlossene und archivierte Datensätze vor direkter Veränderung schützen und Korrekturen nachvollziehbar abbilden.
- **FR-006**: Der Buchhalter MUST Bankbewegungen importieren, abrufen, abstimmen und regelkonform wieder freigeben sowie Zahlungsdateien vorbereiten können.
- **FR-007**: Der Buchhalter MUST alle von Gäld angebotenen Kernberichte als strukturierte Daten und als geeignete Exportdateien abrufen können.
- **FR-008**: Der Buchhalter MUST MWST-Perioden berechnen, kontrollieren, verbuchen und korrigieren können.
- **FR-009**: Der Buchhalter MUST Abschlussvoraussetzungen prüfen, Eröffnungssalden verwalten, Geschäftsjahre abschliessen und Archive erzeugen können; Wiedereröffnung erfordert ein separates ausdrückliches Recht.
- **FR-010**: Das System MUST lang laufende Vorgänge als nachverfolgbare Aufträge mit Status, Fehlergrund und gesichertem Ergebnisdownload bereitstellen.
- **FR-011**: Jede finanzielle Mutation MUST sicher wiederholbar sein, ohne doppelte Belege, Zahlungen, Buchungen oder Lohnläufe zu erzeugen.
- **FR-012**: Jede Mutation MUST mit Organisation, handelnder Person, verwendetem Zugang, Zeitpunkt, Quelle und Ergebnis revisionsnah protokolliert werden.
- **FR-013**: API- und Webzugriff MUST dieselben fachlichen Regeln und Daten verwenden, sodass nach erfolgreicher Verarbeitung derselbe Zustand sichtbar ist.
- **FR-014**: Das System MUST stabile Fehlercodes für Validierung, Rechte, Konflikte, Periodensperren und fachlich unzulässige Statuswechsel liefern.
- **FR-015**: Listen MUST filterbar, sortierbar, paginiert und für inkrementelle Synchronisation nach Änderungszeitpunkt abrufbar sein.
- **FR-016**: Aktivierte Zusatzmodule MUST entsprechend ihren bestehenden Funktionsgrenzen über die API nutzbar sein; deaktivierte Module müssen eindeutig abgelehnt werden.
- **FR-017**: Personal- und Lohndaten MUST durch gesonderte Rechte und explizite Feldfreigaben geschützt werden.
- **FR-018**: Das System MUST relevante Lebenszyklusereignisse für Belege, Buchungen, Abstimmungen, MWST, Exporte, Abschlüsse und Lohn veröffentlichen können.
- **FR-019**: Alle im Rahmen dieses Vorhabens neu entstehenden Erweiterungsbestandteile MUST einem eigenständig aktivierbaren Accountant-API-Modul gehören.
- **FR-020**: Der Gäld-Kern MUST ohne installiertes oder aktiviertes Accountant-API-Modul unverändert starten und funktionieren; das Deaktivieren des Moduls darf keine fachlichen Buchhaltungsdaten löschen oder unlesbar machen.
- **FR-021**: Das Accountant-API-Modul MUST die bestehenden Buchhaltungsdaten und fachlichen Regeln verwenden und darf weder Fachmodelle noch Buchungslogik als eigene Schattenimplementierung duplizieren.
- **FR-022**: Notwendige Änderungen am Gäld-Kern MUST auf generische, dokumentierte und stabile Erweiterungspunkte begrenzt und unabhängig vom Accountant-API-Modul nutzbar sein.
- **FR-023**: Das Accountant-API-Modul MUST seine kompatiblen Gäld-Kernversionen deklarieren, bei erkannter Inkompatibilität geschlossen fehlschlagen und gegen jede unterstützte Version automatisiert geprüft werden.
- **FR-024**: Neue API-Operationen MUST additiv und kollisionsfrei bereitgestellt werden; das Modul darf kein bestehendes Kernverhalten ersetzen oder verdecken.
- **FR-025**: Modul-eigene persistente Daten MUST eindeutig von fachlichen Kerndaten getrennt bleiben; Installation, Update, Deaktivierung und Datenaufbewahrung müssen dokumentiert sein.

### Key Entities

- **API-Zugang**: Einer Person und Organisation zugeordnete Berechtigung mit begrenztem Funktionsumfang und Ablaufdatum.
- **Buchhaltungsstammdaten**: Geschäftsjahr, Konto, MWST-Satz, Kategorie, Kontakt, Bankkonto und optionale Dimensionswerte.
- **Geschäftsbeleg**: Rechnung, Gutschrift oder Ausgabe mit Dateien, Status und verbundener Journalbuchung.
- **Journalbuchung**: Unveränderlich gebuchter Geschäftsfall oder bearbeitbarer Entwurf mit ausgeglichenen Soll-/Haben-Zeilen.
- **Bankbewegung und Abstimmung**: Importierte Zahlung und ihre fachlich nachvollziehbare Zuordnung.
- **Bericht und Exportauftrag**: Periodische Auswertung beziehungsweise nachverfolgbarer Auftrag mit Ergebnisdatei.
- **Abschluss**: MWST- oder Geschäftsjahresabschluss mit Sperrstatus, Abschlussbuchung und Archivnachweis.
- **Lohnlauf**: Periodische Berechnung mit geschützten Mitarbeiterdaten, Lohnabrechnungen und Journalwirkung.
- **Audit-Ereignis**: Unveränderlicher Nachweis einer Aktion und ihres Ergebnisses.
- **Accountant-API-Modul**: Separat aktivierbare Erweiterung mit eigenem Lebenszyklus, eigener Konfiguration, eigenen Betriebsdaten, eigenem Vertrag und deklarierter Kernkompatibilität.
- **Core-Seam**: Kleiner, generischer und stabiler Integrationspunkt im Gäld-Kern, über den Erweiterungen bestehende Fähigkeiten verwenden oder Änderungen beobachten können.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Ein Buchhalter kann einen repräsentativen Monatsabschluss einschliesslich Belegen, Bankabstimmung, MWST-Kontrolle und Reports ohne Web-Eingabemaske vollständig durchführen.
- **SC-002**: 100 % der erfolgreich per API erzeugten finanziellen Zustandsänderungen sind spätestens nach Neuladen in der Weboberfläche sichtbar und einem Akteur zugeordnet.
- **SC-003**: Wiederholung desselben Schreibauftrags erzeugt in 100 % der getesteten Timeout- und Retry-Szenarien keinen doppelten Geschäftsfall.
- **SC-004**: Alle getesteten organisationsfremden Zugriffe und alle Zugriffe ohne benötigtes Teilrecht werden abgewiesen.
- **SC-005**: Ein vorbereiteter Jahresabschluss kann einschliesslich Vorabcheck und Archiv in weniger als 15 Minuten Bedienzeit ausgelöst und kontrolliert werden.
- **SC-006**: Jeder Kernbericht stimmt für dieselbe Organisation und Periode mit der Webdarstellung und den zugrunde liegenden Buchungen überein.
- **SC-007**: Ein neuer API-Nutzer kann anhand der veröffentlichten Schnittstellenbeschreibung mindestens 90 % der dokumentierten Kernabläufe ohne interne Systemkenntnis korrekt ausführen.
- **SC-008**: Gäld startet und die relevanten Kern-Smoketests bestehen sowohl mit deaktiviertem als auch mit nicht vorhandenem Accountant-API-Modul.
- **SC-009**: 100 % des vorhabenspezifischen Transport- und Infrastrukturcodes liegen im Accountant-API-Modul; Änderungen ausserhalb des Moduls sind als generische Core-Seams begründet und separat prüfbar.
- **SC-010**: Für jede als unterstützt deklarierte Gäld-Kernversion bestehen Modul-Boot-, Routen-, Migrations-, Sicherheits- und Vertrags-Smokes automatisiert.
- **SC-011**: Kein Modulrelease überschreibt eine vorhandene Kernroute; eine Kollision oder unpassende Kernversion verhindert die Aktivierung mit einem eindeutigen Diagnosefehler.

## Assumptions

- Der Umfang umfasst zunächst alle Buchhaltungsfunktionen, die Gäld selbst anbietet; definitive Bankfreigaben und behördliche Einreichungen bleiben ohne separate Bank- oder Behördenintegration ausserhalb des Systems.
- Ein menschlicher Buchhalter verwendet standardmässig einen persönlichen, zeitlich begrenzbaren Zugang; technische Organisationstokens bleiben Integrationen vorbehalten.
- Die bestehenden Rollen bilden den Ausgangspunkt. Besonders weitreichende Aktionen wie Wiedereröffnung, Organisationsänderung oder endgültiges Löschen erhalten separate Rechte.
- Weboberfläche und API bleiben alternative Zugänge zum gleichen fachlichen Datenbestand; eine zusätzliche Echtzeitaktualisierung der Weboberfläche ist nicht Voraussetzung der ersten Etappen.
- Payroll und andere optionale Module werden erst nach der vollständigen Kernbuchhaltung umgesetzt und bleiben durch die bestehenden Modulfreigaben begrenzt.
- Die bereits vorhandenen `/api/v1`-Endpunkte bleiben Bestandteil des Gäld-Kerns. Das Accountant-API-Modul ergänzt nur fehlende, kollisionsfreie Endpunkte und bildet zusammen mit der bestehenden API den vollständigen Arbeitskanal.
- Der vorhandene Erweiterungsmechanismus von Gäld ist der vorgesehene Lade- und Lebenszyklusmechanismus. Wo ihm generische Sicherheits- oder Kompatibilitätsfunktionen fehlen, werden diese als kleine Core-Seams ergänzt.
- Die Modulgrenze reduziert Merge-Konflikte, ersetzt aber keine Kompatibilitätsprüfung bei Änderungen an den vom Modul konsumierten Domain-Verträgen.
