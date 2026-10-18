# Tasks: Aufträge einfach führen

- [x] T001 `AllocationCoverageService`: Kategorien Auftrag, allgemein, Lohn, offen; Korrekturen ausgeblendet
- [x] T002 Liste „Zuordnen“ zeigt nur offene Belege, Schalter für allgemeine
- [x] T003 Aktion „Allgemein“: Konto in neuen oder bestehenden Kostenblock, einzelner Beleg an internen Auftrag `ALLG`
- [x] T004 Seite „Aufträge“: Stand der Zuordnung, Kennzahlen, Aufträge je Projekt mit Personen, Netto und Marge
- [x] T005 Formular als Komponente `ProjectFormModal`, Code optional (`ProjectRequest::nextCode`), Schnellknöpfe für Anteile
- [x] T006 Seite eines Auftrags: Belege, Personen mit Netto-Anteil (`SteeringService::splitOf`), Aufträge eines Projekts
- [x] T007 Seite „Personen“: Aufschlüsselung je Auftrag (`jobs` in der Steuerung, auch in der API)
- [x] T008 Begriffe in vier Sprachen, Navigation neu geordnet, Exporte umbenannt
- [x] T009 Tests `AllocationCoverageTest`, `JobPageTest`; API-Bericht behält seine Form
- [x] T010 UI-Durchgang am Bildschirm und auf dem Handy (Demo-Organisation)
- [x] T012 Rückmeldungen: ältere Stornopaare (nur REV-Referenz) ausgeblendet, Spalten bündig, Filter nach Projekt und Person, Sortierung
- [x] T013 „Zuordnen“: neuen Auftrag direkt aus einem Beleg anlegen, der Beleg wird gleich zugeordnet (`allocate_line_id`)
- [ ] T011 Review, Merge nach `deployment`, Rollout nach Freigabe
