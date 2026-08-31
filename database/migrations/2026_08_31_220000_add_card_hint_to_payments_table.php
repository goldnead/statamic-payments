<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Woran der Kaeufer seine Karte wiedererkennt.
 *
 * Ein Nachfassangebot wird ohne erneute Karteneingabe abgebucht. Damit das
 * keine Ueberraschung ist, muss die Seite vorher sagen, *womit* sie abbuchen
 * will — und „mit deiner Karte" ist zu wenig, wenn jemand mehrere hat. Mollie
 * liefert die letzten vier Stellen und die Marke der Karte bei der ersten
 * Zahlung mit; danach sind sie nur noch mit einem Anbieter-Aufruf zu haben,
 * und ein Netzaufruf beim Rendern einer Seite ist keine Loesung.
 *
 * Bewusst nur ein Hinweis, keine Zahlungsdaten: vier Ziffern und ein Name wie
 * „Mastercard" sind keine Kartennummer und fallen nicht unter PCI-DSS. Alles
 * darueber hinaus gehoert dem Anbieter und wird hier nicht gespeichert.
 *
 * Bestandszeilen bleiben null. Das ist der ehrliche Zustand — bei ihnen wurde
 * es nicht mitgeschrieben — und die Seite muss den Fall aushalten, statt zu
 * raten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('card_last4', 4)->nullable()->after('country_source');
            $table->string('card_label', 32)->nullable()->after('card_last4');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['card_last4', 'card_label']);
        });
    }
};
