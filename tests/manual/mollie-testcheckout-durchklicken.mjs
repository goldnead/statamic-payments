/**
 * Klickt eine Mollie-Checkout-Seite im Testmodus bis zur bezahlten Zahlung durch.
 *
 * Wofuer: der Weg hinter Mollie laesst sich nicht im Testlauf nachstellen. Die
 * Kasse erzeugt eine Zahlung, der Kaeufer landet auf Mollies Seite, und was
 * danach passiert (Webhook, Erfuellung, bei Abos die Anlage der Vereinbarung und
 * der zweite Einzug) haengt daran, dass dort jemand auf „paid" klickt. Dieses
 * Skript ist dieser Jemand, damit die Strecke am lebenden System belegbar wird
 * statt behauptet.
 *
 * Aufruf, mit der Mollie-URL, auf die die Kasse weitergeleitet hat:
 *
 *   node tests/manual/mollie-testcheckout-durchklicken.mjs "https://www.mollie.com/checkout/..."
 *
 * Es braucht `playwright` und einen Chrome auf der Maschine, und es haelt keine
 * Zugangsdaten: die URL kommt als Argument herein. Der Status-Radio, auf dem
 * alles beruht, existiert **nur im Testmodus** — gegen eine echte Zahlung
 * laeuft das Skript ins Leere, und das ist die Absicherung.
 *
 * Es liegt unter `tests/manual/`, weil es kein Test ist, den CI ausfuehrt: es
 * braucht einen Browser, das Netz und eine frisch erzeugte Zahlung. Es steht
 * hier statt in einem Scratchpad, weil der offene Punkt aus
 * `backlog-offers-preis-optionen-in-der-kasse` genau dieses Werkzeug braucht und
 * ein Pfad in einem Sitzungsverzeichnis genau dann ins Leere zeigt, wenn ihn
 * jemand sucht.
 *
 * Die Bilder landen in OUT, nicht im Repo.
 *
 * **Der Durchlauf ist nicht der Beweis.** Dieses Skript belegt den Weg bis zum
 * Anbieter und zurueck, mehr nicht. Was den offenen Punkt schliesst, steht
 * danach in der Datenbank: traegt die Zahlung `subscription_intent`, existiert
 * nach dem Webhook eine `Subscription`, steht `times` auf zwei statt drei,
 * heisst die erste Rechnungszeile „Rate 1 von 3", kommt der zweite Einzug. Eine
 * stille Einmalzahlung erzeugt dieselbe Danke-Seite wie eine richtige
 * Vereinbarung — genau die Fehlerform, gegen die das Ticket geschrieben ist.
 * Wer hier aufhoert, hat einen Screenshot und keinen Beleg. Siehe
 * `backlog-offers-preis-optionen-in-der-kasse`.
 */
import { chromium } from 'playwright'
const URL = process.argv[2]
const OUT = '/tmp/claude-1000/shots-e2e'
import { mkdirSync } from 'node:fs'; mkdirSync(OUT, { recursive: true })

const b = await chromium.launch({ channel: 'chrome' })
const p = await b.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 })
await p.goto(URL, { waitUntil: 'networkidle' })
await p.screenshot({ path: `${OUT}/30-mollie-methodenwahl.png` })
console.log('1) Methodenwahl:', await p.title())

// Testmodus: irgendeine Methode waehlen, meist Kacheln mit Namen
for (const name of [/ideal/i, /credit card|kreditkarte/i, /bancontact/i, /paypal/i]) {
  const el = p.getByRole('link', { name }).or(p.getByRole('button', { name })).first()
  if (await el.count()) { await el.click(); break }
}
await p.waitForLoadState('networkidle')
await p.waitForTimeout(1500)
await p.screenshot({ path: `${OUT}/31-mollie-status.png` })
console.log('2) nach Methodenwahl:', p.url())

// Mollie-Testseite: Status ist ein Radio, danach "Continue"
const radio = p.locator('input[type=radio][value="paid"]').first()
if (await radio.count()) {
  await radio.check({ force: true })
} else {
  await p.getByText(/^paid$/i).first().click()
}
await p.screenshot({ path: `${OUT}/31b-mollie-status-gewaehlt.png` })
const weiter = p.getByRole('button', { name: /continue/i }).or(p.getByRole('link', { name: /continue/i })).first()
await weiter.click()
await p.waitForLoadState('networkidle')
console.log('3) bezahlt, weitergeleitet nach:', p.url())

await p.waitForTimeout(2000)
await p.screenshot({ path: `${OUT}/32-nach-zahlung.png` })
console.log('4) Endstand:', p.url())
await b.close()
