<?php

namespace Goldnead\StatamicPayments\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Facades\User;
use Throwable;

/**
 * Für wen gekauft wird, wenn nicht für die Person an der Kasse.
 *
 * Ein Aufrufer nennt es über `$details['for']` (siehe {@see PaymentDetails}),
 * nie über freie `meta`: `meta.entitlement_subject` gehört dem Paket. Der Grund
 * ist, dass daran der Zugang hängt. Ein Feld, das jeder Aufrufer frei befüllen
 * kann, ist eines, über das ein Kauf einem fremden Datensatz Zugang schreibt.
 *
 * Angenommen werden drei Formen, alles andere ist ein Programmierfehler und
 * fliegt:
 *
 * - ein gespeichertes Eloquent-Modell (etwa ein Team),
 * - ein Statamic-Benutzer (Eloquent oder Datei),
 * - eine `SubjectReference` aus statamic-entitlements (oder ein Objekt mit
 *   den öffentlichen Texten `type` und `id`).
 *
 * Der Typ muss einer sein, hinter dem entitlements einen Datensatz findet, und
 * der Datensatz muss existieren. Sonst gilt die Adresse wie ohne `for`, und das
 * Log sagt, warum: ein Kauf scheitert nicht an einem gelöschten Team.
 */
final class PurchaseSubject
{
    public const META_KEY = 'entitlement_subject';

    /**
     * @return array{type: string, id: string}|null
     *
     * @throws InvalidArgumentException bei einer Form, die kein Subjekt sein kann
     */
    public static function fromCaller(mixed $for): ?array
    {
        if ($for === null) {
            return null;
        }

        $pair = self::pair($for);

        if ($pair !== null && self::resolvableType($pair['type']) && self::exists($pair['type'], $pair['id'])) {
            return $pair;
        }

        Log::warning('statamic-payments: `for` names no record entitlements can find; the access goes to the buyer\'s address instead.', [
            'for' => $pair ?? get_debug_type($for),
        ]);

        return null;
    }

    /**
     * Das Subjekt aus einer gespeicherten `meta`, geprüft auf Form und Typ.
     * Die Existenz nicht: ein Zugang überlebt den Datensatz, dem er gehört.
     *
     * @return array{type: string, id: string}|null
     */
    public static function fromMeta(mixed $meta): ?array
    {
        $raw = data_get($meta, self::META_KEY);

        if (! is_array($raw)) {
            return null;
        }

        $type = is_string($raw['type'] ?? null) ? trim($raw['type']) : '';
        $id = is_string($raw['id'] ?? null) || is_int($raw['id'] ?? null) ? trim((string) $raw['id']) : '';

        return $type !== '' && $id !== '' && self::resolvableType($type) ? ['type' => $type, 'id' => $id] : null;
    }

    /** Whether entitlements can find a record behind this subject type. */
    public static function resolvableType(string $type): bool
    {
        if ($type === 'user') {
            return true;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        return class_exists($class) && is_subclass_of($class, Model::class);
    }

    public static function exists(string $type, string $id): bool
    {
        try {
            $class = Relation::getMorphedModel($type) ?? $type;

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                return $class::query()->whereKey($id)->exists();
            }

            return $type === 'user' && User::find($id) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Subjekte, deren Name den Suchbegriff enthält: je Alias der Morph-Map mit
     * einer Spalte `name` eine Abfrage. Für die Suche im Control Panel.
     *
     * @return array<string, list<string>> Typ → Kennungen
     */
    public static function matching(string $term, int $limit = 200): array
    {
        $found = [];
        $escaped = addcslashes($term, '%_\\');

        foreach (Relation::morphMap() as $alias => $class) {
            try {
                if (! is_subclass_of($class, Model::class)) {
                    continue;
                }

                $model = new $class;

                if (! Schema::connection($model->getConnectionName())->hasColumn($model->getTable(), 'name')) {
                    continue;
                }

                $ids = $class::query()
                    ->whereRaw("name LIKE ? ESCAPE '\\'", ['%'.$escaped.'%'])
                    ->limit($limit)
                    ->pluck($model->getKeyName())
                    ->map(fn ($id) => (string) $id)
                    ->all();

                if ($ids !== []) {
                    $found[$alias] = array_values($ids);
                }
            } catch (Throwable) {
                // Eine Suche. Eine fehlende Tabelle kostet diesen Typ, nie die Liste.
            }
        }

        return $found;
    }

    /** @return array{type: string, id: string}|null */
    private static function pair(mixed $for): ?array
    {
        if ($for instanceof StatamicUser) {
            if (method_exists($for, 'model') && ($model = $for->model()) instanceof Model) {
                $for = $model;
            } else {
                $id = method_exists($for, 'id') ? (string) $for->id() : '';

                return $id === '' ? null : ['type' => 'user', 'id' => $id];
            }
        }

        if ($for instanceof Model) {
            $key = $for->getKey();

            return $for->exists && $key !== null && $key !== ''
                ? ['type' => $for->getMorphClass(), 'id' => (string) $key]
                : null;
        }

        if (is_object($for) && isset($for->type, $for->id) && is_string($for->type) && is_string($for->id)) {
            $type = trim($for->type);
            $id = trim($for->id);

            return $type === '' || $id === '' ? null : ['type' => $type, 'id' => $id];
        }

        throw new InvalidArgumentException(
            'statamic-payments: `for` ist ein Eloquent-Modell, ein Statamic-Benutzer oder eine SubjectReference, nicht '.get_debug_type($for).'.'
        );
    }
}
