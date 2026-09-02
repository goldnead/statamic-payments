# Changelog

## 1.17.0 — 2026-09-02

### Zahlungs-Detailseite mit Kommunikationsprotokoll

Utilities → Zahlungen → Klick auf eine Zeile (oder „Details" im Zeilenmenü) öffnet
`cp/utilities/payments/{id}`: Kopf mit Betrag, Status und Zeitpunkten; Panels für Positionen (Art,
Menge, Einzelpreis, Angebot), Käufer (E-Mail, Name, Land, Anschrift aus `meta.address`, USt-IdNr. aus
`meta.vat_id`), Einwilligung nach § 356 Abs. 5 BGB (Zeitpunkt, Wortlaut, Fassung der Belehrung),
Zugangsfenster (`meta.access`), Herkunft (UTM, Verweis, Einstiegsseite), Zahlungsmittel, Erstattungen,
Verknüpfungen (Erstbestellung, Nachfassangebote, Abo, Rechnung, Widerrufe, Kündigungen) und
**Kommunikation**. Ist `statamic-webhook-manager` installiert, ein Panel „Webhook-Zustellungen"
(`WebhookLog::forSubject('payment', id)`). Dasselbe Recht wie das Listing; auf Mehrmarken-Installationen
mit gesetzter Marke ist eine fremde Zahlung eine 404. Register S·8.

Neu: Tabelle `payment_communications` und die Fassade `PaymentLog` — `PaymentLog::mail($payment,
'invoice', $to, $subject)`, `::note()`, `::record()`, `::for()`. Ein Fehler beim Schreiben wird geloggt
und bricht nie einen Kaufpfad. Das Addon trägt selbst ein: Portal-Link (an der jüngsten Bestellung der
Adresse), Eingangsbestätigung Widerruf (bei zugeordneter Zahlung), Eingangsbestätigung Kündigung und
Kündigungsbestätigung aus dem Portal (an der jüngsten Zahlung des Abos), Abbruch-Erinnerung.
`statamic-invoices` trägt seine Rechnungs-Mail ein. Ereignis `PaymentCommunicationLogged`.

### Warenkorbabbruch-Mail

`abandoned.mail.enabled` schickt je angekündigtem Checkout eine Erinnerung an die Adresse darauf —
nicht, wenn `statamic-suppression` die Adresse führt (dann eine Notiz im Protokoll). `template` nimmt
einen email-templates-Slug mit den Variablen `buyer.email`, `buyer.name`, `order.lines`,
`order.total`, `order.currency`, `resume_url`; ohne Vorlage geht eine eingebaute, veröffentlichbare
Blade-Mail (de/en). `resume_url` ist ein signierter Link (`abandoned.mail.resume_days`, Vorgabe 14)
auf eine Bestellseite im Portal-Layout: Positionen, Gesamtpreis, Widerrufshinweis, Haken nach § 356
Abs. 5 mit dem Wortlaut aus `meta.withdrawal` (sonst `messages.order_consent`) und die Schaltfläche
„Zahlungspflichtig bestellen" (§ 312j Abs. 3). Der GET legt nichts an; erst der signierte POST startet
über `Checkout::resume()` denselben Warenkorb als neue Zahlung — gleiche Positionen, Käufer, Herkunft,
Rabatt und Marke, `meta.resumed_from` zeigt zurück, die Zustimmung ist frisch (jetzt, gezeigter
Wortlaut, nur mit Haken) und wird nie kopiert. Ein zweiter Klick binnen einer Stunde findet die offene
Kasse wieder. Oder eine eigene Adresse mit `{payment}`. Neue Spalte `payments.recovered_at`: gesetzt,
wenn eine erinnerte Zahlung doch bezahlt wird, auch über den neu gestarteten Checkout. Register K·8.

### Zahlungsarten

`methods` (Liste von Mollie-Kennungen oder `STATAMIC_PAYMENTS_METHODS` mit Kommas) geht als `method`
in die Mollie-Anfrage; ohne Angabe kein Schlüssel. Der Käufer wird nur dann zum Merken angemeldet
(`customerId`, `sequenceType: first`), wenn mindestens eine der Methoden ein Mandat hinterlassen kann.
`Support\PaymentMethods` hält die zwei Listen, das README die Tabelle. Register K·18.

### Nachzügler

- `EntitlementsBridge::grantFor()` gibt `meta.access` (`starts_at`, `days`, aus `Offer::accessWindow()`)
  als `startsAt`/`expiresAt` an `Entitlements::grant()` weiter. Register K·5.
- `payment_items.offer`: das Angebot, über das eine Position verkauft wurde — aus
  `PaymentDetails::offer_handles` (Produkt-Handle → Angebots-Handle) oder aus dem `offer`-Schlüssel,
  den der Katalog an die Zeile heftet; sonst null.
- `payments:prune-legal-drafts` löscht unbestätigte Widerrufs- und Kündigungserklärungen nach sieben
  Tagen (`--days`, `--dry-run`).
- Kein `email:filter` mehr im Addon; die Formulare nutzen `EmailAddress::rule()` (war schon so).

### Widerrufsbutton nach § 356a BGB

Seit 19.06.2026 Pflicht, bis hierher nicht vorhanden. Neu: ein öffentlicher, zweistufiger Weg
ohne Login unter `!/statamic-payments/widerruf` (Config `withdrawal.prefix`). Schritt 1 nimmt
Name, E-Mail, Bestellkennung, Kontaktmittel und Nachricht; Schritt 2 zeigt die Angaben und die
Schaltfläche „Widerruf bestätigen"; danach geht sofort die Eingangsbestätigung mit Kennung
(`W-` plus acht Zeichen ohne 0/O/1/I), Datum, Uhrzeit und Zeitzone an den Verbraucher und eine
Meldung an `withdrawal.notify` (sonst `portal.from`, sonst `mail.from`). Schritt 3 zeigt Kennung
und Zeit, sonst nichts, und bleibt für jeden mit der Kennung lesbar; Schritt 2 nur für den
Browser, der erklärt hat. Idempotent: ein zweiter Klick ist ein Widerruf, eine Mail, eine Zeit.

Tabelle `payment_withdrawals`. Die Zuordnung zur Zahlung passiert nach der Bestätigung,
serverseitig, nur bei eindeutigem Treffer (Adresse plus unsere Id oder die des Anbieters);
das Formular verrät nie, ob eine Bestellung existiert. Eine Zustimmung nach § 356 Abs. 5 am
Treffer wird dem Händler als `right_expired_hint` mitgegeben, nicht dem Verbraucher vorgehalten;
ebenso, ob die Erklärung nach `withdrawal.days` (Vorgabe 14) einging.

Footer: `{{ payments:withdrawal_url }}`, `Legal\Links::withdrawal()`, Beschriftung aus
`withdrawal.button` („Vertrag widerrufen"). Control Panel: Utility „Widerrufe" mit Zuordnung,
Hinweisen, Filter offen/erledigt und der Action „Als erledigt markieren" (Notiz); Rechte
`access withdrawals utility` und `handle payment withdrawals`.

Rechtliche Entscheidungen dieser Fassung, von Adrian zu prüfen, keine Rechtsberatung: ohne
Login; kein Bestandsorakel; unzugeordnet ist zulässig und wird gemeldet; erloschenes Recht ist
Hinweis, keine Ablehnung; IP nur als gesalzener Hash; Musterbelehrung bleibt Host-Sache
(`withdrawal.policy_url`). Das Lese-Recht ist core's Utility-Recht, nicht ein zweites
`view payment withdrawals` — ein Schalter je Tür.

### Kündigungsbutton nach § 312k BGB, ohne Login

Der Portal-Weg (`/konto/kuendigen` → Magic-Link) bleibt als Komfortweg. Neu daneben, in
derselben Mechanik wie der Widerruf: `!/statamic-payments/kuendigung` (Config
`cancellation.prefix`), Schaltfläche „Verträge hier kündigen", Bestätigungsseite mit Art der
Kündigung (ordentlich/außerordentlich, letztere mit Pflicht-Grund), Identifikation und
gewünschtem Zeitpunkt unter „jetzt kündigen", danach Bestätigung per Mail und auf der Seite mit
Datum, Uhrzeit und genanntem Zeitpunkt. Tabelle `payment_cancellations`.

Ein eindeutig zugeordnetes **laufendes** Abo wird sofort über `Subscriptions::cancel()` beim
Anbieter gekündigt (Anbieter zuerst, Zeile danach; `provider_cancelled_at`). Mehrdeutig, nicht
laufend oder vom Anbieter verweigert: nichts am Abo geändert, Händler gemeldet, Verbraucher
bekommt die Eingangsbestätigung trotzdem. Footer: `{{ payments:cancellation_url }}`. Control
Panel: Utility „Kündigungen", Rechte `access cancellations utility` und
`handle payment cancellations`.

Rechtliche Entscheidung dieser Fassung, von Adrian zu prüfen: ein genannter Zeitpunkt in der
Zukunft hält die Kündigung beim Anbieter nicht auf — gekündigt wird die nächste Abbuchung, der
Zeitpunkt steht in Zeile und Meldung. Keine Rechtsberatung.

Nach Kritik (02.09.2026) geändert: Beim Anbieter gekündigt wird nur, was über die
**Anbieter-Kennung** getroffen wurde; ein Treffer über unsere laufende Nummer wird zugeordnet,
aber nicht gekündigt, und der Händler bekommt „über Kundennummer zugeordnet, bitte prüfen"
(die Nummer ist erratbar, die Kennung nicht). `OfferController` schreibt einen eingereichten
`consent_text` nur, wenn er `messages.order_consent` (de/en) oder einem Eintrag in
`consent.accepted_texts` entspricht — sonst Server-Wortlaut plus `Log::warning('consent text
mismatch')`. Dazu: benannte Limiter `statamic-payments.withdrawal` / `.cancellation` statt
anonymem `throttle:`, `legal.timezone` für die Zeit auf Belegen, der Grund einer
außerordentlichen Kündigung steht in der Bestätigungsmail, eine abgelaufene Session führt
zurück aufs Formular statt auf eine 404, `MerchantAddress` warnt im Log beim Rückfall auf
`mail.from`, und die Kündigungsliste blendet „Art" und „Gewünscht zum" per Vorgabe aus.

Nebenbei: `Tags\Offer` heißt jetzt `Tags\Payments` (Handle unverändert `payments`), und
`Portal\EmailAddress::rule()` ist die Adressprüfung als Validierungsregel — `email:filter`
hätte jede Adresse mit Umlaut abgelehnt.

### Die Einwilligung wird festgehalten statt verworfen (§ 356 Abs. 5 BGB)

`payments` bekommt zwei Spalten, `consent_at` und `consent_text`. Bis hierher wurde
`confirmed => accepted` geprüft und dann vergessen; der Kommentar im Code nannte das „the
record", es gab keines. Jetzt gehen Zeitpunkt und der **vollständige Wortlaut**, der neben dem
Haken stand, mit dem ersten INSERT in die Zeile — über `PaymentDetails`, wie `country`. Der Text
selbst und keine Versionsnummer, weil der Wortlaut sich ändert und „hat zugestimmt" ohne die
Fassung nichts belegt.

Beide Spalten sind unveränderlich: ein späteres Umschreiben oder Löschen wirft eine
`LogicException`. Von null auf einen Wert geht es genau einmal. Bestandszeilen bleiben null.

`OfferController` schreibt die Zustimmung an die Folgezahlung (Wortlaut aus dem versteckten
Feld `consent_text`, sonst der neue Sprachstring `messages.order_consent`); `FollowUp::accept()`
erbt sie **nicht** von der Erstbestellung.

Rechtliche Entscheidungen dieser Fassung, von Adrian zu prüfen, keine Rechtsberatung:
beide Angaben oder keine; Zeitpunkt nie in der Zukunft; Wortlaut nicht leer und höchstens
4000 Zeichen, abgelehnt statt gekürzt; jeder Kauf trägt seine eigene Zustimmung; der Zeitpunkt
ist der Eingang des Formulars beim Server, nicht der Klick im Browser. Wer das Addon ohne
`statamic-funnels` einsetzt, baut Bestellzusammenfassung, Schaltfläche und Einwilligungstext
selbst und übergibt `consent_at`/`consent_text` — das Addon rendert keine Kasse.

## 1.16.0 — 2026-08-31

### Ein Mandat gehört dem Menschen, nicht dem Gerät

`FollowUp::eligible()` nimmt jetzt zusätzlich die Adresse des Käufers, der gerade vor dem
Bildschirm sitzt, und lehnt ab, wenn sie nicht zu der Zahlung passt, gegen die abgebucht werden
soll. Dasselbe gilt für `accept()`, das die Adresse als fünftes Argument entgegennimmt und an die
Prüfung weiterreicht. Wer nichts übergibt, bekommt das bisherige Verhalten — es gibt Aufrufer, die
ihren Käufer aus einer signierten Sitzung kennen und keine Adresse zur Hand haben.

Der Anlass war ein reproduzierter Fall in `statamic-funnels`: dort hing die Frage „wer ist das"
an einem Besuchs-Cookie mit dreißig Tagen Laufzeit. Wer als Zweiter am selben Rechner durch
denselben Funnel ging, bekam kein Kartenformular mehr. Mollie buchte per gespeichertem Mandat
`sequenceType: recurring` auf den Kunden des ersten Kaufs ab, und Zugang wie Rechnung liefen auf
dessen Adresse — die frisch eingegebene wurde von `FollowUp` schlicht überschrieben. Auf einem
Familienrechner, im Büro oder in einer Bibliothek ist das kein Randfall.

Diese Fassung entfernt die Möglichkeit nicht, sie verlangt nur einen Beleg. Steht an einer der
beiden Seiten keine Adresse, gibt es nichts zu widersprechen, und es bleibt bei den übrigen
Bedingungen.

### Woran der Käufer seine Karte wiedererkennt

Neue Spalten `payments.card_last4` und `payments.card_label`, gefüllt aus dem, was der Anbieter
bei der Zahlung ohnehin mitliefert (`RemotePayment::$cardLast4` / `$cardLabel`). Gebraucht werden
sie auf der Seite eines Nachfassangebots: die darf nicht abbuchen, ohne vorher zu sagen, womit —
§ 312j Abs. 3 BGB verlangt die wesentlichen Angaben unmittelbar über dem Knopf, die Zahlungsart
eingeschlossen. Zu holen sind sie nur im Moment der Zahlung; später kostet es einen
Anbieter-Aufruf beim Rendern einer Seite.

Vier Ziffern und ein Name wie „Mastercard" sind keine Kartennummer und fallen nicht unter PCI-DSS.
Mehr wird nicht gespeichert. Bestandszeilen bleiben null, und jede Seite muss das aushalten.

### Migration

`2026_08_31_220000_add_card_hint_to_payments_table` — zwei nullbare Spalten auf `payments`.

## 1.15.0 — 2026-08-30

### Neu: `Brands::readerId()` — die fehlende Hälfte von `Brands::only()`

`only()` nimmt eine nullbare Marken-ID und macht mit jedem Fall das Richtige. Nur musste sich jede
aufrufende Stelle diese ID selbst besorgen, und die naheliegende falsche Antwort lag direkt daneben:
`stampId()`. Das beantwortet „auf welche Marke wird diese **neue** Zeile geschrieben" und liefert
dort, wo keine Marke gesetzt ist, eine **Null**. An `only()` weitergereicht heißt Null nicht „zeig
nichts", sondern „zeig die Zeilen, die niemand beansprucht hat" — also alles, was ein Webhook oder
ein Konsolenbefehl angelegt hat.

Eine so geschriebene Liste sieht auf einer Einmarken-Installation richtig aus, sieht auf einer
Mehrmarken-Installation mit gewählter Marke richtig aus, und zeigt still die herrenlosen Zeilen,
sobald jemand sie ohne Marke öffnet.

```php
Brands::only($query, Brands::readerId());   // richtig
Brands::only($query, Brands::stampId());    // die herrenlosen Zeilen
```

Null hier, Null dort — zwei verschiedene Fragen, wie der Klassenkommentar schon sagte. Der
Kommentar allein hat nicht gereicht.

### Neu: `Catalogue::contribute()` — der Katalog kann jetzt aufzählen

`Catalogue::extend()` beantwortet „was kostet dieser Handle". Es kann nicht beantworten „was gibt
es überhaupt", weil ein Resolver immer nur einen einzelnen Handle zu sehen bekommt. Jeder
Bildschirm, der eine Produktliste anbietet, war damit blind für alles, was nicht in der
Config-Datei steht: die Produktauswahl im Angebotsformular zeigte drei von sechs Produkten und wies
das Speichern anschließend mit 422 ab, weil die `Rule::in()` aus derselben blinden Liste gebaut war.

```php
Catalogue::contribute(fn () => [
    'atemkurs' => ['name' => 'Atemkurs', 'amount_cent' => 4900],
]);
```

**Zwei Nähte, nicht eine, und das ist Absicht.** `contribute()` zählt auf, `extend()` bepreist.
`find()` bleibt damit eine Config-Suche plus ein paar billige Resolver und läuft nie durch eine
Datenbank, weil jemand nach einem Handle gefragt hat, den es nicht gibt — `find()` erreicht alles,
was ein Browser schickt. Der Preis dieser Trennung ist eine Regel: **wer beisteuert, muss auch
auflösen.** Ein Addon, das einen Handle aufzählt, den es nicht bepreisen kann, legt eine
unverkäufliche Zeile in die Auswahl.

**Config gewinnt bei Gleichstand.** Ein Preis in einer Datei steht in der Versionsverwaltung und
wurde absichtlich hingeschrieben; eine Tabellenzeile darf einen Deploy nicht still überstimmen.

Beigesteuerte Einträge ohne ganzzahligen `amount_cent` >= 0 werden nicht gelistet. Config-Einträge
werden weiterhin ungeprüft gelistet — sie jetzt zu prüfen hieße, dass der vertippte Preis einer
laufenden Installation beim Upgrade aus ihrer eigenen Auswahl *verschwindet*, und das liest sich
wie „nichts zu verkaufen".

## 1.14.0 — 2026-08-29

### Behoben: Umsatzzahlen zählten jede Marke, und verloren die letzte Sekunde

Drei Fehler derselben Familie, keiner davon mit einem roten Test.

**Die Marke.** `paidInPeriod()` und `refundedInPeriod()` summierten jede Marke, ganz gleich was der
Markenwähler oben rechts sagte. Ein Test, der eine eigene Zeile gegen zwei fremde stellt, meldet
gegen den alten Stand 13.000 statt 1.000 Cent. `brandScoped()` ist hier wortgleich zu
`TableMetric::brandScoped()` abgeschrieben — diese Klasse baut nicht darauf auf, sie ist älter und
liest zwei Tabellen und einen Join —, denn zwei Schreibweisen einer Regel sind der Weg, auf dem zwei
Kacheln nebeneinander verschiedene Dinge zählen.

**Das Fenster.** Die Obergrenze war einschließend, und eine Bindung formatiert `23:59:59.999999`
als `Y-m-d H:i:s`. Auf einer Millisekunden-Spalte fiel damit jeder Verkauf der letzten Sekunde
heraus: auf SQLite immer, auf einfachen MySQL-Zeitstempeln zufällig richtig, in beiden Fällen
unsichtbar. Jetzt halboffen. Der Test schreibt eine Millisekunde und meldet gegen den alten Stand
1 statt 2.

**Der Join.** `productRows()` geht nicht durch `paidInPeriod()`, sondern fängt bei den Positionen an
und verbindet zurück — genau die Form, die an einem zentral gesetzten Filter vorbeiläuft. Dort steht
jede Bedingung jetzt ausgeschrieben.

### Neu: sieben Zahlen in Insights

Brutto, netto, erstattet, Bestellungen, Käufer, mittlerer Bestellwert und Erstattungsquote, mit
Aufteilungen nach Kampagne, Quelle, Produkt und Land. `statamic-insights` liest dafür **keine**
Tabelle dieses Addons mehr — die Rechnerei liegt jetzt auf der Seite des Zauns, der die Daten
gehören. Die Kopplung ist in beide Richtungen `suggest`, nie `require`.


### Neu: `grants` darf eine Liste sein

Ein Produkt konnte genau einen Zugang vergeben. Für ein Bündel — eine Zeile, ein Preis, drei
Dinge — reichte das nicht, und `statamic-offers` 1.4 verkauft genau so etwas.

`grants` nimmt jetzt auch eine Liste:

    'fruehlings-buendel' => [
        'name' => 'Frühlings-Bündel',
        'amount_cent' => 4900,
        'grants' => ['noten-fruehling', 'playback-fruehling', 'workshop-mitschnitt'],
    ],

Eine einzelne Zeichenkette bleibt erlaubt und ist unverändert der Normalfall; alte Konfigurationen
ändern sich nicht. Doppelte Slugs werden einmal vergeben — zwei Zeilen mit derselben Aussage sind
kein zweiter Zugang.

Betroffen sind alle vier Wege, an denen ein Zugang hängt: Kauf, Verlängerung, Kündigung und
Erstattung. Jeder Slug ist ein eigener Versuch, damit der Fehlschlag des zweiten nicht den dritten
verhindert — und die Zeile im Log nennt den fehlenden Slug statt „das Bündel".

**Vorher war das ein stiller Totalausfall, kein Teilausfall.** Eine Liste fiel an `is_string()`
heraus, und `slugFor()` gab `null` zurück: nicht das erste Stück, sondern nichts. Zahlung durch,
Rechnung geschrieben, kein Zugang, keine Fehlermeldung.


### Neu: Selbstbedienung für Käufer

Ein Käufer kann jetzt ohne Konto seine Bestellungen ansehen, seine Rechnung herunterladen, sein
Abo kündigen und sein Zahlungsmittel wechseln. Der Weg hinein ist ein signierter, ablaufender
Link an die Adresse, die auf der Bestellung steht — kein Passwort, kein Konto, weil ein Käufer
eines Notenhefts keins anlegen wollte.

Die Mechanik ist die von `statamic-preference-center`, übernommen statt neu erfunden: doppelte
Drosselung, eine Antwort für jeden Ausgang, Antwortzeit auf einen Boden gehalten, Sitzungs-ID beim
Öffnen erneuert. Dasselbe Aussehen, dieselbe Palette, kein Build-Schritt — die Seite wird aus einem
Mailprogramm geöffnet und muss beim ersten Byte da sein.

**§ 312k BGB liefert das Addon mit.** Kündigungsschaltfläche mit eigener URL, Bestätigungsseite,
die den Vertrag benennt, und danach eine Bestätigung in Textform mit Datum und Uhrzeit — als Mail,
nicht als grüner Kasten, der beim Neuladen weg ist. **Jeder vorgeschriebene Wortlaut steht in
`lang/*/portal.php`** und in keiner PHP-Datei; er gehört vor einen Anwalt, und die Vorschrift ist
schon einmal geändert worden. `--tag=statamic-payments-translations`.

Gekündigt wird über `Subscriptions::cancel()`: der Anbieter wird zuerst gefragt, seine Antwort wird
geschrieben. Antwortet er nicht — oder nimmt er den Aufruf an und lässt das Abo weiterlaufen —,
bleibt die Zeile unangetastet und der Käufer bekommt eine ehrliche Meldung statt einer Bestätigung.

### Neu: `brand_id` auf `payments` und `subscriptions`

Bisher trug hier nichts eine Marke, und `statamic-invoices` schrieb genau das in eine
Ausnahme-Klasse: „a brand is not recoverable from the payment either". Für den Kundenbereich ist
diese Lücke nicht bezahlbar — auf einem Mandanten-Host ist die Marke auf der Bestellung das
Einzige, was den Link der Marke A von der Bestellung der Marke B fernhält.

Die Naht kennt **drei** Zustände, nicht zwei: „keine Mandanten", „Mandanten, und die aktuelle ist
bekannt" und „das Geschwister-Addon ist da und hat nicht geantwortet". Ein `bool` fasst die letzten
beiden zusammen, und ein `catch (Throwable) { return false; }` um `multiBrandEnabled()` hätte einen
werfenden Lizenz-Rückruf — den der Host selbst schreibt — in „diese Installation hat keine
Mandanten" verwandelt, also in „kein Filter". Ein defensiver Fang, der nach außen aufmacht, ist
schlimmer als kein Fang: er erzeugt eine Seite, die funktioniert.

`default(0)`, kein Fremdschlüssel, keine harte Abhängigkeit auf `brand-context`: auf jeder
Ein-Marken-Installation steht überall 0 und nichts ändert sich. Gestempelt wird aus der Marke, in
der die Zeile entsteht; entsteht sie im Webhook für eine andere Zeile — ein Abo-Zyklus, eine
Nachfass-Zahlung —, erbt sie deren Marke, statt eine zu raten. Im Mandanten-Betrieb gehört eine
Zeile auf 0 niemandem und wird niemandem gezeigt.

**Der Altbestand wird abgeleitet, nicht geraten.** Die erste Fassung dieser Migration nahm die
kleinste Marken-Id und schrieb sie auf jede bestehende Zahlung und jedes Abo. Am Demo-Playground
machte das elf Zahlungen zu „nordlicht", und `invoices:brand-check` fand sieben Rechnungen, die in
der Reihe einer anderen Marke stehen als die Zahlung, zu der sie gehören. Die Rechnungen hatten
recht — und seit `statamic-invoices` die Spalte liest, wäre die geratene Antwort ab sofort in neue
Dokumente weitergereicht worden.

Drei Wege, stärkster zuerst: eine Zahlung mit Rechnung bekommt die Marke der Rechnung, ein Abo die
Marke seiner ersten Zahlung, eine Folgeabbuchung die Marke der Zeile, zu der sie gehört. Gefahren
bis nichts mehr dazukommt, weil jeder Weg den nächsten speist. Was danach übrig bleibt, steht
weiter auf `0` und wird ins Log geschrieben; die Standardmarke wird nirgends eingesetzt. Der
Zugriff auf `invoices` läuft über `Schema::hasTable()` und ist ein Hinweis, keine Voraussetzung:
das Rechnungs-Addon ist ein `suggest`, und die echte Abhängigkeit läuft andersherum.

### Neu: `payments:brand-backfill`

Die kaputte Migration ist committet und auf mindestens zwei Installationen schon gelaufen; dort
läuft sie nie wieder, und die Zeilen stehen auf der falschen Marke statt auf `0`. Dieser Befehl
fährt **dieselbe** Ableitung (eine Stelle, `Support\BrandBackfill`, nicht zweimal geschrieben) und
korrigiert eine Zeile nur dann, wenn eine abgeleitete Quelle ihr widerspricht. Eine Zeile, für die
sich nichts ableiten lässt, bleibt, wie sie ist — auch wenn sie die geratene Marke trägt: fehlender
Beleg ist kein Beleg. Gezählt und ausgegeben wird beides.

`--dry-run` zeigt nur. Ohne die Option wird geschrieben, mit einer Zusammenfassung, wie viele
Zeilen aus welcher Quelle stammen.

### Neu: eine weiche Naht zur Rechnung

`Contracts\InvoiceSource` + `Support\Invoices` (Registry, wie `Catalogue`). Ohne
Rechnungs-Addon zeigt sich die Bestellung ohne Download, statt zu brechen.
`Integrations\InvoiceBridge` erkennt `goldnead/statamic-invoices` **an der Form, nicht am Typ**:
eine einzige Zeichenkette nennt dessen Fassade, alles danach ist `method_exists`. Dort wird gerade
parallel PDF und Zustellung gebaut; eine Brücke gegen die heutigen Klassen wäre eine Wette auf
unfertige Arbeit.

### Neu: `Contracts\MandateGateway`

Zahlungsmittel wechseln über den Mandats-Weg des Anbieters. `MollieGateway` implementiert es; der
Kundenbereich fragt, ob das gebundene Gateway es kann, und nennt Mollie nirgends beim Namen. Auf
Mollie kostet das den Käufer einen Cent — es gibt dort keine Null-Betrags-Autorisierung —, und der
Betrag steht über der Schaltfläche statt später auf dem Kontoauszug.

### Behoben

- `Payment` kannte die Attributions-Spalten aus 1.13 in seinem `@property`-Block nicht.
- `Checkout` fragte `request()` mit `?->`, was nie null wird.

### Added

- **Seven metrics for `statamic-insights`** — gross revenue, net, refunded,
  orders, buyers, average order and refund rate, with splits by campaign,
  source, product and country. The addon that owns the data now owns the query;
  Insights owns the screen. Optional in both directions: a `suggest`, a
  `class_exists` guard, and nothing loaded when the sibling is absent.
- `HasFilterOptions` on every metric, so the currency switch on the reporting
  screen is filled by this addon rather than guessed by the other one.

## 1.13.0

### Neu: eine Naht für Angaben, die dem Paket nichts bedeuten

`FollowUp::accept()`, `Checkout::start()` und `Subscriptions::start()` nehmen jetzt einen
Parameter `$details` entgegen: `meta`, `country` und `country_source`. Was dort steht, wird in
dieselbe Transaktion geschrieben wie die Zahlung selbst und steht damit fest, **bevor** der
Anbieter gerufen wird. Beschrieben in `docs/follow-up-offers.md`.

Was der Aufrufer mitgibt, überschreibt nichts, was das Paket selbst setzt. Betrag, Produkt,
Status, die Kennungen des Anbieters, die Verbindung zur Eltern-Zahlung: wer sie mitschickt,
bekommt eine `InvalidArgumentException`, bevor eine Zeile angelegt und bevor Geld bewegt wurde.
Ein still verworfener Betrag sähe für den Aufrufer aus wie ein gesetzter, und der Unterschied
fällt dann erst auf dem Kontoauszug auf.

### Behoben: die Folge-Zahlung hatte keine Stelle, an der ihre Angaben ankommen konnten

`FollowUp::accept()` legte eine Zahlung an und rief den Anbieter, ohne dass die aufrufende
Strecke etwas an diese Zahlung heften konnte. Wer die Anschrift oder einen eigenen Verweis
brauchte, trug beides **nach** dem Aufruf nach, und das ist ein Rennen gegen den Webhook: meldet
der Anbieter die Zahlung schneller, als der Aufrufer schreibt, liest der Rechnungsschreiber eine
Zeile ohne Anschrift.

Auffliegen konnte das erst ab 250 EUR. Bis dorthin reicht die Kleinbetragsrechnung nach
§ 33 UStDV, die ohne Anschrift des Leistungsempfängers auskommt; darüber ist es eine fehlende
Pflichtangabe auf einem Beleg, der nicht mehr korrigiert, sondern storniert und neu geschrieben
wird. Ein Folgeangebot ist typischerweise das billige Ding neben der Bestellung, und genau
deshalb hält die Lücke still, bis jemand ein teures danebenstellt.

### Behoben: eine Abo-Zahlung ab dem zweiten Zyklus hatte nie eine Anschrift

Ein Zyklus entsteht im Webhook, weil der Anbieter von sich aus abbucht. Die Zeile wurde
ausschließlich aus dem Abo gebaut und erbte nichts von der Zahlung, die das Abo begonnen hat.
Damit fehlte jeder Zyklus-Rechnung die Anschrift, und ein Abo ist die Umsatzart, die die
250-EUR-Grenze am ehesten reißt. Erschwerend: die Spalte `subscription_id` steht zum Zeitpunkt
von `PaymentPaid` noch nicht, ein Listener hatte also nicht einmal einen Zeiger, um sie
nachzuschlagen.

Ein Zyklus erbt jetzt die `meta` der ersten Zahlung, ohne die Schlüssel, die das Paket selbst
führt, und trägt in `meta['cycle_of']` die Kennungen des Abos und der ersten Zahlung. Das Land
wird bewusst nicht geerbt: das trägt der Anbieter nach, und sein Beleg wiegt schwerer.

### Behoben: ein Testzeitraum ohne Betrag wurde nie ein Abo

`Subscriptions::start()` schrieb `subscription_intent` erst, nachdem `Checkout::start()`
zurückgekehrt war. Bei einem Testzeitraum, der heute nichts kostet, ist die Zahlung zu diesem
Zeitpunkt schon erfüllt: der Katalog preist sie mit null, `Checkout::start()` erfüllt sie selbst,
`PaymentPaid` feuert, `startFromPayment()` sieht nach der Absicht und findet keine. Ergebnis: eine
bezahlte Bestellung, kein Abo, keine Logzeile, kein Unterschied zu einer gewöhnlichen Einzelzahlung.

Die Absicht geht jetzt in den Checkout hinein statt hinterher. Bei einem Testzeitraum ohne Betrag
bleibt es dabei, dass kein Abo entsteht (ohne Belastung gibt es kein Mandat, und ohne Mandat kein
Abo), aber es steht jetzt als Fehler im Log statt gar nirgends.

## 1.12.0

### Fixed — wer über ein Angebot kaufte, bekam keinen Zugang

`EntitlementsBridge` las `config('statamic-payments.products')` direkt und ging damit an jedem
Resolver vorbei, den ein anderes Addon am `Catalogue` angemeldet hat — und `statamic-offers` meldet
einen an. Jede Bestellung über ein Angebot gewährte deshalb **gar nichts**: Zahlung erfolgreich,
Geld da, Zugang nie.

So still, wie ein Fehler nur sein kann. „Dieses Produkt gewährt nichts" und „dieses Produkt kenne
ich nicht" kamen beide als dasselbe `null` zurück — kein Fehler, keine Logzeile, kein Unterschied zu
einem Produkt, das rechtmäßig nichts gewährt.

`slugFor()` und `grantLine()` gehen jetzt über den Katalog. Dasselbe galt für `productName()` im
Control Panel, wo statt eines Namens der rohe Handle `offer:fruehling-upsell` stand.

Belegt gegen das echte `statamic-entitlements`, nicht gegen eine Attrappe: das letzte Mal, als diese
Brücke nur gegen einen Doppelgänger geprüft wurde, hatte sie auf keiner einzigen echten Installation
je funktioniert.

## 1.11.0

### What's new

- **Quantity bounds in the catalogue.** The quantity is the only figure a checkout accepts from a
  request — the unit price never is — so a product that offers a *variable* one now says what it
  allows: `min_quantity` and `max_quantity`. That is what makes a donation or a pay-what-you-want
  possible without the rule falling: the unit price stays server-side, and what comes from the
  browser is a bounded integer.

  Opt-in. A product that says nothing behaves exactly as before, capped by a new global
  `max_quantity` (default 1000) that exists only so a mistyped or hostile figure cannot become a
  five-figure charge.

### What's fixed

- **A currency is not always divided by a hundred.** `amount_cent` is minor units and `amount()`
  hard-coded two decimals. The Japanese yen has none and the Tunisian dinar three, so 1.000 ¥ went
  to the provider as either ten times or a hundredth of the price. `Support\Money` knows the
  zero- and three-decimal currencies; two remains the default, because a table of every ISO 4217
  code is one nobody maintains.

- **The return URL is checked against this application.** No shipped code path feeds it from a
  request, so this was never a hole in the addons — it was a trapdoor for hosts: an application
  passing `$request->input('return')` through would build an open redirect, and one with unusually
  good cover, because it sits behind a real and successful payment. An external target is now
  dropped rather than refused: the buyer has paid by then, and failing the checkout over a bad
  return address would take their money and show them an error.

  Approach taken from `thomasvantuycom/statamic-mollie` (MIT, checked at the repository).

## 1.10.0

### What's new

- **Refunds are recorded, and a full one withdraws the access.** Until now the refund happened in the
  provider's dashboard, nothing here heard about it, and somebody who was repaid kept their course
  indefinitely. The sibling has had `revoke()` with a mandatory reason all along — nobody called it.

  `Refunds::record()` notes an **amount and a time**, never a status: an order half repaid is still a
  paid order, and a status forced to choose would be wrong about the other half. Idempotent per the
  provider's refund id, because "the customer was refunded three times" is the kind of number that
  ends up in an annual return.

  A **full** refund revokes every product line of the order. A **partial** one does not: half the
  money back is not half a course, and there is no honest way to withdraw half an access — so it is
  recorded and left to a person.

  Verified against the real entitlements addon, not a stand-in.

- **`payments:prune-unpaid`** deletes checkouts that were started and never paid, after a number of
  days the site names (`prune_unpaid_after_days`, off by default). A paid order carries a retention
  obligation; an abandoned checkout carries the opposite. Deleted rather than anonymised — an
  anonymised record with no purpose is still a record.

  Everything paid, fulfilled, refunded or in a final status is left alone, as is anything inside a
  running reminder sequence: an automation whose trigger vanishes underneath it fails halfway through.

## 1.9.0

### What's new

- **The two facts an invoice needs, recorded while they still exist.** Neither can be reconstructed
  later, which is why they land here rather than in an invoicing addon: every real sale that happens
  before this is a row that can never be invoiced correctly.

  **`payments.country`** and `country_source` — the buyer's country, frozen at checkout, normalised
  to ISO 3166-1 alpha-2. Anything else is dropped rather than stored: a column that holds
  "Deutschland", "DE" and "de" is one nobody can compute a rate from, and a wrong rate looks like an
  answer. Where the checkout has none, fulfilment fills the gap from the provider — which is the
  better evidence anyway, since it comes from the card issuer.

  **`payment_items.discount_cent`** — the share of the discount that fell on each line, distributed
  proportionally to line value. From a single total, a voucher across a 7% line and a 19% line
  cannot be split, and the invoice is then not visibly wrong but indeterminate. Rounding is a named
  rule, not a hope: integer division, leftover cents to the largest lines first, so the parts always
  add up to the whole. A percentage voucher and the amount it produces split identically — which is
  what makes the rule safe to apply after the fact.

  Existing rows keep `null` and `0`. That is the honest state.

## 1.8.0

### What's new

- **A subscription now keeps its entitlement in step.** `SubscriptionRenewed` pushes the window to
  the provider's own `next_payment_at`; `SubscriptionCancelled` and `SubscriptionEnded` close an
  open-ended grant at the end of the paid period. Until now every installation wrote these three
  listeners itself.

  Each rule is deliberately not the obvious one. A renewal calls the sibling's `renew()`, not
  `grant()` — that call refuses to widen an existing window on purpose, so once a month would mean
  twelve grants a year and "does this person have access" would become an aggregation. Cancelling
  **closes** rather than revokes: somebody who cancels has paid for the period they are in. And a
  renewal without a date from the provider changes nothing and logs why — a guessed end is a grant
  that stops too early or too late, and the customer finds out first.

  Requires `statamic-entitlements` 1.1. Against an older sibling the bridge stays quiet rather than
  writing the wrong thing.

  Covered twice: once against a stand-in as strict as the real class, and once **against the sibling
  itself** — three cycles, one entitlement, ending on the third date. The last time this bridge was
  tested only against a stand-in, it had never worked on a single real installation.

## 1.7.0

### What's new

- **Abandoned checkouts.** `payments:sweep-abandoned` announces every checkout that was started and
  left unpaid past a waiting period, as `CheckoutAbandoned`, once each. With `statamic-automations`
  installed the trigger **Checkout Abandoned** appears under Payments and needs no code.

  Once-only is claimed with a conditional update on a new `abandoned_notified_at` column, the same
  way fulfilment and failure already are: the sweep runs on a schedule and may overlap itself, and a
  reminder arriving twice is a support ticket nobody can reproduce. A payment that arrives afterwards
  clears the claim, so a sequence can end on `PaymentPaid`.

  **Off by default, and that is not caution about the code.** The address on an unfinished checkout
  was given to complete a purchase, not to receive advertising.

## 1.6.0 — 2026-08-25

### Fixed — the entitlements bridge had never once worked

The bridge handed the buyer's **email string** to `statamic-entitlements`, which
refuses a bare string on purpose: a grant belongs to a `(type, id)` subject so it
can outlive the record it points at. So every paid order on a real installation
logged *"the entitlements bridge failed"* and granted nothing.

Built, wired, documented, tested — and never working, because the tests bound a
stub that accepted anything. A mock that says yes to everything proves you made
a call, not that the call was accepted. Found by installing both addons side by
side and paying with a real card.

The bridge now passes a `SubjectReference('email', …)`, falling back to the old
string for an older sibling. `statamic-entitlements` is a dev dependency of this
package **because of the test**: a skipped test is what let this through.

### New — the webhook URL is configurable

A provider checks that a webhook URL is reachable from *its* side before it will
create a payment, so a developer on `localhost` cannot check out at all. Mollie
answers 422 and the checkout is refused.

- `webhook_url` as a string overrides the route: a tunnel's address goes there.
- `false` omits it, and the status has to be pulled instead —
  `Fulfilment::handle($providerId)` is the same method the webhook route calls.
  Fine for a demo, **wrong for production**, and the config comment says so.

### Changed

- `$actions` and `$scopes` are no longer declared: core discovers `src/Actions/`
  and `src/Scopes/`, and an explicit list goes stale the moment somebody adds a
  class.

## 1.5.0 — 2026-08-25

### Being charged again, on a rhythm

**One mechanism, three faces.** A subscription runs until somebody stops it, a payment plan stops
counting, and a trial starts late. Not three features: a plan is a subscription with an end, and
building them apart would have meant three cancellation paths and three ways to get the last
instalment wrong.

- `Subscriptions::start()` takes a first payment, and the agreement is created **only after the
  webhook confirms it** — a mandate is what a provider needs, and a payment is what leaves one.
- Every cycle after that is an ordinary `Payment`: same `PaymentPaid`, same one-time claim. A
  subscription therefore grants access every month without any listener knowing subscriptions exist.
- A cycle the provider charged on its own gets a row and a line, built from the **agreement** and
  never from the webhook.
- `SubscriptionStarted`, `SubscriptionRenewed`, `SubscriptionCancelled`, `SubscriptionEnded` and
  `SubscriptionStartFailed`.
- A **Subscriptions** utility screen: both faces in one listing, a read-only detail with the payments
  made against each agreement, and cancelling as a row and bulk action.

**A trial is honest about its trade.** Mollie cannot store a card without charging something — no
SetupIntent, no zero authorisation. So `trial_amount_cent` says what the trial charges, and a site
that sets it to nothing gets no card and a buyer who has to come back.

### Found by a reviewer, and worth naming

- **The provider call was inside a database transaction.** Anything failing after it rolled the local
  row back while the provider kept a running subscription: somebody charged every month, forever,
  with no row here and no alarm — a cycle for an unknown agreement is indistinguishable from a stray
  webhook. Now the row is committed first and the event fires after, the pattern `Checkout` and
  `FollowUp` already follow.
- **`add('1 month')` on 31 January lands on 3 March.** February is skipped and the provider bills on
  the 3rd for ever after. Measured. Months are now clamped to the end of one.
- The provider is asked how an agreement is doing on every cycle, so a suspension after failed
  charges reaches the row.
- A straggler no longer ends a finished plan twice.
- A cycle carries a `PaymentItem`, so reports built over lines stop leaving out all recurring revenue.

### Found by looking at the screen

- **The Control Panel toasts everything green.** A returned value is toasted as success, and a thrown
  exception becomes `success: false`, which is *also* toasted green. A refused cancellation therefore
  arrived with a tick. The action now pushes `Toast::error()` and returns `['message' => false]`.
- Sorting by "next charge" pulled cancelled agreements (NULL) to the top.

## 1.4.0 — 2026-08-25

### What's new

- **A product may cost zero.** The provider is never called; the payment is marked paid and fulfilled
  on the spot, through the same one-time claim and the same `PaymentPaid` event, so a listener that
  grants access cannot tell the difference.
- **`Discount`** — an optional fourth argument to `Checkout::start()`, for a total lower than its
  lines. This addon still knows nothing about coupons: what a code is worth belongs to pricing, and
  pricing lives in `statamic-offers`. What lives here is `discount_code` and `discount_cent` on the
  payment, so an old receipt keeps saying what came off after the coupon has expired or changed.
- The discount is clamped: never more than the total, never negative. A bug upstream should cost a
  wrong price, not a payment the provider rejects.

### Changed

- `Catalogue::find()` now accepts `amount_cent => 0`. A **missing or mistyped** price is still
  refused — `null`, a negative number and `'19,00'` all still return nothing. `0` is a statement;
  those are mistakes, and a mistake must not become a giveaway. The test that asserted zero was
  unsellable has been changed to say so, not deleted.

## 1.3.0

### What's new

- **`Catalogue::extend()`** — a seam another addon can contribute priced things through.
  `goldnead/statamic-offers` uses it so that an upsell with its own price resolves like any other
  product, and every guard in here applies to it unchanged. **The configured catalogue always wins**:
  an addon may add, never reprice what the site has already decided.
- **`Checkout::start(..., $returnUrl)`** — where the provider sends the buyer back to. A funnel
  passes its own page, because a buyer who returns outside the flow they were walking has been
  dropped halfway through a purchase, and whatever was meant to follow the sale never happens.


## 1.2.0

### What's new

- **A payment carries lines, not one product.** An order bump — a checkbox at
  checkout adding a second item — is now one payment with two lines:
  `start(['noten-paket', 'uebungsblaetter'])`, or with quantities
  `start(['noten-paket' => 1, 'uebungsblaetter' => 3])`. The total is their sum,
  each line keeps the name it was sold under, and a line is never a second
  payment.
  **All or none:** a handle that is not in the catalogue refuses the whole
  checkout instead of quietly dropping the line, and two currencies in one
  payment are refused for the same reason.
  Done now rather than later on purpose: the schema change costs nothing while
  the addon has no installs, and would be a migration on other people's servers
  afterwards.
- **Follow-up offers**, off by default. An offer shown after a payment, charged
  without asking for card details a second time. `docs/follow-up-offers.md` is
  the whole story, and most of it is not technical: in Germany a follow-up order
  still needs its own unambiguously labelled button with the essential details
  directly above it. What is saved is the card number, **not** the consent.
- The offer disappears once taken. A second click, a double submit, a reloaded
  confirmation — all of them would otherwise charge again for the same thing. A
  *refused* charge does not count as taken.
- A follow-up is never treated as paid on acceptance. A recurring charge is
  accepted now and settled later; only the webhook decides, exactly as at
  checkout.
- The entitlements bridge grants **every** paid line, not only the first. A bump
  the buyer ticked and paid for is as bought as the thing they came for.

### What's fixed

- A payment's lines are deleted with it even where the database does not enforce
  the foreign key — which on SQLite it quietly does not. Orphaned lines would
  have counted towards every revenue report ever run.


## 1.1.0

### What's new

- **A screen in the Control Panel.** Utilities → Payments: when, what, how much, paid, **fulfilled**,
  and who bought it. Built on core's `Listing`, so it behaves like the rest of the CP.
- The column that earns the screen is `Fulfilled`, and the filter *Paid, not fulfilled* narrows the
  list to the one case worth chasing: money arrived, nothing delivered. Mollie cannot answer that
  question; only the site can.
- Status and fulfilment are real Statamic filters, so they show a badge, survive sorting and paging,
  and can be saved as a view. A query parameter of my own would have been dropped by the listing
  after the first fetch.
- Read-only. Refunds and disputes stay at Mollie, where the record is complete.
- Access is the `access payments utility` permission, registered by core along with the screen.
- CI now rebuilds the committed Control Panel bundle and fails if it differs from the sources.


## 1.0.0

Initial release. Mollie checkout behind a provider-agnostic seam, a webhook that trusts nothing in
the request, fulfilment that runs exactly once, and two events.
