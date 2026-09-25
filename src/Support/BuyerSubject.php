<?php

namespace Goldnead\StatamicPayments\Support;

use Goldnead\StatamicPayments\Integrations\EntitlementsBridge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Statamic\Facades\User;
use Throwable;

/**
 * Für wen gekauft wurde, wenn es nicht die Person ist, die bezahlt hat.
 *
 * `meta.entitlement_subject = {type, id}` (so setzt es `Teams::checkout()`)
 * wird im Control Panel zu „Team Kammerchor Nord" statt nur zu einer Adresse.
 * Der Name kommt aus dem Datensatz hinter dem Morph-Alias; findet sich keiner
 * mehr, bleibt die Kennung stehen. Kein Name ist besser als ein geratener.
 */
final class BuyerSubject
{
    /**
     * @return array{type: string, id: string, type_label: string, name: string, display: string}|null
     */
    public static function describe(mixed $meta): ?array
    {
        $raw = data_get($meta, 'entitlement_subject');

        if (! is_array($raw)) {
            return null;
        }

        $type = is_string($raw['type'] ?? null) ? trim($raw['type']) : '';
        $id = is_string($raw['id'] ?? null) || is_int($raw['id'] ?? null) ? trim((string) $raw['id']) : '';

        if ($type === '' || $id === '' || ! EntitlementsBridge::resolvableType($type)) {
            return null;
        }

        $key = 'statamic-payments::messages.subject_type_'.$type;
        $typeLabel = __($key);
        $typeLabel = $typeLabel === $key ? Str::headline($type) : $typeLabel;

        $name = self::name($type, $id) ?? '#'.$id;

        return [
            'type' => $type,
            'id' => $id,
            'type_label' => $typeLabel,
            'name' => $name,
            'display' => $typeLabel.' '.$name,
        ];
    }

    private static function name(string $type, string $id): ?string
    {
        try {
            if ($type === 'user' && Relation::getMorphedModel('user') === null) {
                $user = User::find($id);

                $name = is_object($user) && method_exists($user, 'name') ? $user->name() : null;

                return self::text($name) ?? self::text($user?->email());
            }

            $class = Relation::getMorphedModel($type) ?? $type;

            if (! is_subclass_of($class, Model::class)) {
                return null;
            }

            $record = $class::query()->find($id);

            if (! $record instanceof Model) {
                return null;
            }

            foreach (['name', 'title', 'label', 'email'] as $attribute) {
                if (($value = self::text($record->getAttribute($attribute))) !== null) {
                    return $value;
                }
            }
        } catch (Throwable) {
            // Eine Anzeige. Eine fehlende Tabelle oder ein umbenanntes Modell
            // kostet den Namen, nie die Seite.
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
