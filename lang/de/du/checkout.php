<?php

/*
|--------------------------------------------------------------------------
| Kasse, geduzt
|--------------------------------------------------------------------------
|
| Gilt, wenn `statamic-payments.anrede` auf `du` steht. Nur die Zeilen, die
| sich von `../checkout.php` unterscheiden.
|
*/

return [
    'thanks_expired_body' => 'Die Seite nach dem Kauf ist nur eine Weile erreichbar. Was du gekauft hast, findest du in deinem Kundenkonto. Dort meldest du dich mit der E-Mail-Adresse an, mit der du bestellt hast.',
    'refused_blocked' => 'Diese Bestellung können wir leider nicht annehmen. Bitte schreib uns, wenn du Fragen hast.',
    'refused_rate_limited' => 'Zu viele Bestellversuche in kurzer Zeit. Bitte versuch es in einigen Minuten noch einmal.',
    'refused_captcha' => 'Bitte bestätige, dass du kein Roboter bist, und schick die Bestellung noch einmal ab.',
    'refused_country' => 'Dieses Angebot ist in deinem Land leider nicht erhältlich.',
];
