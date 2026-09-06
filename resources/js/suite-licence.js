/**
 * Der Lizenzhinweis der Suite.
 *
 * Ein Hinweis, kein Schloss. Er sperrt nichts, verlangsamt nichts, prueft
 * nichts und ruft nichts ab — die Entscheidung, ob er ueberhaupt erscheint,
 * faellt serverseitig in `Cp\SuiteLicence` und kommt hier als fertiges
 * `true`/`false` an.
 *
 * Warum kein Vue-Component: er gehoert an keine Seite. Er soll einmal im
 * Control Panel stehen, egal wo jemand gerade ist, und danach lange Ruhe geben.
 * Ein Component braeuchte einen Ort im Seitenbaum, den es dafuer nicht gibt.
 *
 * Warum `localStorage` und keine Benutzereinstellung: das Wegklicken ist eine
 * Bequemlichkeit, kein Zustand, den der Server kennen muss. Eine
 * Benutzereinstellung waere ein Schreibzugriff und eine Wanderung durch die
 * API fuer die Frage „hat diese Person das schon gesehen". Faellt der Speicher
 * aus — privates Fenster, geloeschte Daten, Browser, der ihn sperrt —, dann
 * erscheint der Hinweis eben wieder. Das ist der harmlose Ausgang.
 */

const SPEICHER_SCHLUESSEL = 'statamic-payments.suite-licence.dismissed-at';

/**
 * Wann zuletzt weggeklickt wurde, als Zeitstempel — oder null.
 *
 * Jeder Zugriff in try/catch: in einem privaten Fenster und bei gesperrten
 * Seitendaten wirft schon das Lesen. Ein Hinweis darf das Control Panel nicht
 * mit einer Ausnahme anhalten.
 */
function weggeklicktAm() {
    try {
        const wert = window.localStorage.getItem(SPEICHER_SCHLUESSEL);
        const zahl = wert === null ? NaN : Number.parseInt(wert, 10);

        return Number.isFinite(zahl) ? zahl : null;
    } catch (e) {
        return null;
    }
}

function merkeWeggeklickt() {
    try {
        window.localStorage.setItem(SPEICHER_SCHLUESSEL, String(Date.now()));
    } catch (e) {
        // Dann erscheint er beim naechsten Mal wieder. Nicht schoen, aber
        // besser als eine Ausnahme im Control Panel.
    }
}

/**
 * Ob die Ruhezeit nach dem Wegklicken abgelaufen ist.
 *
 * Statamics eigenes Modal laesst sich auf einer Produktionsdomain nur fuenf
 * Minuten stummschalten und ist nicht schliessbar. Fuer ein Produkt, das
 * ausdruecklich nichts erzwingt, waere das feindselig — deshalb Tage statt
 * Minuten, und die Zahl kommt aus der Config.
 *
 * Ein Zeitstempel aus der Zukunft (verstellte Uhr, kopiertes Profil) wuerde den
 * Hinweis sonst fuer immer verschlucken. Er zaehlt deshalb als „nie
 * weggeklickt".
 */
export function ruheAbgelaufen(dismissedAt, tage) {
    if (dismissedAt === null || dismissedAt > Date.now()) {
        return true;
    }

    return Date.now() - dismissedAt > tage * 24 * 60 * 60 * 1000;
}

function baueHinweis(url) {
    const kasten = document.createElement('div');
    kasten.setAttribute('role', 'status');
    kasten.setAttribute('data-suite-licence-notice', '');
    kasten.style.cssText = [
        'position:fixed', 'inset-inline-end:1rem', 'inset-block-end:1rem', 'z-index:1000',
        'max-width:26rem', 'padding:1rem 1.125rem', 'border-radius:0.5rem',
        'background:#0f1629', 'color:#eef2f8', 'box-shadow:0 8px 30px rgba(0,0,0,.35)',
        'font-size:0.875rem', 'line-height:1.5',
    ].join(';');

    const text = document.createElement('p');
    text.style.cssText = 'margin:0 0 0.75rem';
    // Der Ton ist der ganze Punkt des Tickets: kein Vorwurf und keine Drohung.
    // Die Suite laeuft, sie ist nur nicht lizenziert.
    text.textContent = 'Diese Installation nutzt die Statamic Addon Suite ohne Lizenz. Es ist nichts gesperrt und nichts eingeschränkt — dieser Hinweis ist die einzige Folge.';

    const reihe = document.createElement('div');
    reihe.style.cssText = 'display:flex;gap:0.75rem;align-items:center';

    const kaufen = document.createElement('a');
    kaufen.href = url;
    kaufen.target = '_blank';
    kaufen.rel = 'noopener noreferrer';
    kaufen.textContent = 'Lizenz kaufen';
    kaufen.style.cssText = 'color:#eef2f8;text-decoration:underline;font-weight:600';

    const schliessen = document.createElement('button');
    schliessen.type = 'button';
    schliessen.textContent = 'Verstanden';
    schliessen.style.cssText = 'margin-inline-start:auto;background:none;border:1px solid #98a5bb;color:#c7d0de;border-radius:0.375rem;padding:0.3rem 0.7rem;cursor:pointer';
    schliessen.addEventListener('click', () => {
        merkeWeggeklickt();
        kasten.remove();
    });

    reihe.append(kaufen, schliessen);
    kasten.append(text, reihe);

    return kasten;
}

/**
 * Zeigt den Hinweis, wenn der Server ihn angefordert hat und die Ruhezeit um
 * ist. Tut sonst nichts — und zwar wirklich nichts: kein Abruf, kein Timer,
 * kein Eintrag im DOM.
 */
export function suiteLicenceNotice() {
    let config = null;

    try {
        config = Statamic.$config.get('statamicPaymentsSuiteLicence');
    } catch (e) {
        return;
    }

    if (!config || config.needed !== true) {
        return;
    }

    const tage = Number.parseInt(config.days, 10);

    if (!ruheAbgelaufen(weggeklicktAm(), Number.isFinite(tage) && tage > 0 ? tage : 30)) {
        return;
    }

    // Nur einmal, auch wenn `booting` in einer langen Sitzung mehrfach laeuft.
    if (document.querySelector('[data-suite-licence-notice]')) {
        return;
    }

    document.body.appendChild(baueHinweis(config.url || 'https://suite.adriangoldner.dev'));
}
