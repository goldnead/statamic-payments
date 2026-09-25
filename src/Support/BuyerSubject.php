<?php

namespace Goldnead\StatamicPayments\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Statamic\Facades\User;
use Throwable;

/**
 * Für wen gekauft wurde, wenn es nicht die Person ist, die bezahlt hat.
 *
 * `meta.entitlement_subject` (gesetzt aus `$details['for']`, siehe
 * {@see PurchaseSubject}) wird im Control Panel zu „Team Kammerchor Nord"
 * statt nur zu einer Adresse. Der Name kommt aus dem Datensatz hinter dem
 * Morph-Alias; findet sich keiner mehr, bleibt die Kennung stehen. Kein Name
 * ist besser als ein geratener.
 *
 * Eine Liste ruft {@see self::preload()} einmal für die ganze Seite: eine
 * Abfrage je Typ statt einer je Zeile. Ohne das lädt {@see self::describe()}
 * den einen Namen nach und merkt ihn sich für die Anfrage.
 */
final class BuyerSubject
{
    /** @var array<string, array{name: string|null, email: string|null}|null> "type:id" → Name und Adresse */
    private static array $names = [];

    /**
     * @return array{type: string, id: string, type_label: string, name: string, display: string}|null
     */
    public static function describe(mixed $meta): ?array
    {
        $pair = PurchaseSubject::fromMeta($meta);

        if ($pair === null) {
            return null;
        }

        ['type' => $type, 'id' => $id] = $pair;

        $cacheKey = $type.':'.$id;

        if (! array_key_exists($cacheKey, self::$names)) {
            self::load($type, [$id]);
        }

        $typeLabel = self::typeLabel($type);
        $name = self::$names[$cacheKey]['name'] ?? '#'.$id;

        return [
            'type' => $type,
            'id' => $id,
            'type_label' => $typeLabel,
            'name' => $name,
            'display' => $typeLabel.' '.$name,
        ];
    }

    /**
     * Wie {@see self::describe()}, aber nichts, wenn das Subjekt die Person
     * ist, die bezahlt hat: ein Kauf „für" sich selbst ist kein „gekauft für".
     * ChoirLive übergibt bei jedem Einzelkauf den eigenen Benutzer als `for`;
     * die Liste zeigte den Namen dann zweimal.
     *
     * @return array{type: string, id: string, type_label: string, name: string, display: string}|null
     */
    public static function describeFor(mixed $meta, ?string $buyerEmail): ?array
    {
        $described = self::describe($meta);

        if ($described === null || ! self::isPersonType($described['type'])) {
            return $described;
        }

        $subjectEmail = self::$names[$described['type'].':'.$described['id']]['email'] ?? null;
        $buyer = is_string($buyerEmail) ? trim($buyerEmail) : '';

        return $subjectEmail !== null && $buyer !== '' && strcasecmp($subjectEmail, $buyer) === 0
            ? null
            : $described;
    }

    /**
     * Ein Wort, nie ein Klassenname. Ohne Morph-Alias steht im Typ die Klasse
     * (`App\Models\User`); ein Benutzermodell heißt dann „Benutzer", alles
     * andere nach dem kurzen Klassennamen.
     */
    private static function typeLabel(string $type): string
    {
        $handle = self::isPersonType($type) ? 'user' : $type;

        if (str_contains($handle, '\\')) {
            $handle = Str::snake(class_basename($handle));
        }

        $key = 'statamic-payments::messages.subject_type_'.$handle;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : Str::headline($handle);
    }

    /** Ob hinter dem Typ eine Person steht: `user` oder ein Benutzermodell ohne Alias. */
    private static function isPersonType(string $type): bool
    {
        if ($type === 'user') {
            return true;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        return class_exists($class) && (
            is_subclass_of($class, Authenticatable::class)
            || $class === config('auth.providers.users.model')
        );
    }

    /**
     * Die Namen aller Subjekte einer Seite, eine Abfrage je Typ.
     *
     * @param  iterable<mixed>  $metas
     */
    public static function preload(iterable $metas): void
    {
        // Frisch je Seite: in einem langlebigen Prozess (Octane) hielte der
        // Speicher sonst den Namen von vor einer Umbenennung.
        self::forget();

        $byType = [];

        foreach ($metas as $meta) {
            if (($pair = PurchaseSubject::fromMeta($meta)) !== null && ! array_key_exists($pair['type'].':'.$pair['id'], self::$names)) {
                $byType[$pair['type']][$pair['id']] = $pair['id'];
            }
        }

        foreach ($byType as $type => $ids) {
            self::load($type, array_values($ids));
        }
    }

    /** Für Tests, und für einen langlebigen Prozess zwischen zwei Anfragen. */
    public static function forget(): void
    {
        self::$names = [];
    }

    /** @param  list<string>  $ids */
    private static function load(string $type, array $ids): void
    {
        foreach ($ids as $id) {
            self::$names[$type.':'.$id] = null;
        }

        try {
            if ($type === 'user' && Relation::getMorphedModel('user') === null) {
                foreach ($ids as $id) {
                    $user = User::find($id);
                    $name = is_object($user) && method_exists($user, 'name') ? $user->name() : null;
                    $email = self::text($user?->email());
                    self::$names['user:'.$id] = ['name' => self::text($name) ?? $email, 'email' => $email];
                }

                return;
            }

            $class = Relation::getMorphedModel($type) ?? $type;

            if (! is_subclass_of($class, Model::class)) {
                return;
            }

            foreach ($class::query()->whereKey($ids)->get() as $record) {
                $email = self::text(self::attribute($record, 'email'));
                $name = null;

                foreach (['name', 'title', 'label'] as $attribute) {
                    if (($name = self::text(self::attribute($record, $attribute))) !== null) {
                        break;
                    }
                }

                self::$names[$type.':'.$record->getKey()] = ['name' => $name ?? $email, 'email' => $email];
            }
        } catch (Throwable) {
            // Eine Anzeige. Eine fehlende Tabelle oder ein umbenanntes Modell
            // kostet den Namen, nie die Seite.
        }
    }

    /**
     * Je Spalte einzeln abgesichert: ein Modell mit
     * `preventAccessingMissingAttributes` wirft bei einer Tabelle ohne
     * `email`, und der Name ginge sonst mit verloren.
     */
    private static function attribute(Model $record, string $key): mixed
    {
        try {
            return $record->getAttribute($key);
        } catch (Throwable) {
            return null;
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
