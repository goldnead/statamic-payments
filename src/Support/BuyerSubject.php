<?php

namespace Goldnead\StatamicPayments\Support;

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
    /** @var array<string, string|null> "type:id" → Name */
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

        $key = 'statamic-payments::messages.subject_type_'.$type;
        $typeLabel = __($key);
        $typeLabel = $typeLabel === $key ? Str::headline($type) : $typeLabel;

        $cacheKey = $type.':'.$id;

        if (! array_key_exists($cacheKey, self::$names)) {
            self::load($type, [$id]);
        }

        $name = self::$names[$cacheKey] ?? '#'.$id;

        return [
            'type' => $type,
            'id' => $id,
            'type_label' => $typeLabel,
            'name' => $name,
            'display' => $typeLabel.' '.$name,
        ];
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
                    self::$names['user:'.$id] = self::text($name) ?? self::text($user?->email());
                }

                return;
            }

            $class = Relation::getMorphedModel($type) ?? $type;

            if (! is_subclass_of($class, Model::class)) {
                return;
            }

            foreach ($class::query()->whereKey($ids)->get() as $record) {
                foreach (['name', 'title', 'label', 'email'] as $attribute) {
                    if (($value = self::text($record->getAttribute($attribute))) !== null) {
                        self::$names[$type.':'.$record->getKey()] = $value;

                        break;
                    }
                }
            }
        } catch (Throwable) {
            // Eine Anzeige. Eine fehlende Tabelle oder ein umbenanntes Modell
            // kostet den Namen, nie die Seite.
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
