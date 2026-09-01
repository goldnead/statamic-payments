<?php

namespace Goldnead\StatamicPayments\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Statamic\Actions\Action;

/**
 * „Als erledigt markieren" — für Widerrufe und Kündigungen dieselbe Geste.
 *
 * Was erledigt heißt, entscheidet der Mensch: erstattet, abgelehnt, geklärt.
 * Die Zeile merkt sich nur, dass jemand hingesehen hat, wann, und was er dazu
 * notiert hat. Eine Notiz statt eines Status-Menüs, weil die Fälle zu
 * verschieden sind für vier Optionen und zu selten für ein Workflow-System.
 *
 * Registrierte Actions liegen auf jeder Liste im Control Panel, deshalb ist
 * `visibleTo` der Zaun und `authorize` das Schloss. Die Unterklassen sagen,
 * welches Modell und welches Recht.
 */
abstract class MarkHandled extends Action
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    abstract protected function permission(): string;

    public static function title()
    {
        return __('statamic-payments::messages.legal_mark_handled');
    }

    public function icon(): string
    {
        return 'checkmark';
    }

    public function visibleTo($item)
    {
        $model = $this->model();

        return $item instanceof $model && $item->getAttribute('handled_at') === null;
    }

    public function authorize($user, $item)
    {
        return $user->can($this->permission());
    }

    public function buttonText()
    {
        /** @translation */
        return __('statamic-payments::messages.legal_mark_handled');
    }

    public function confirmationText()
    {
        return __('statamic-payments::messages.legal_mark_handled_confirm');
    }

    protected function fieldItems()
    {
        return [
            'note' => [
                'type' => 'textarea',
                'display' => __('statamic-payments::messages.legal_handled_note'),
                'instructions' => __('statamic-payments::messages.legal_handled_note_instructions'),
                'validate' => ['nullable', 'string', 'max:4000'],
            ],
        ];
    }

    public function run($items, $values)
    {
        $note = trim((string) ($values['note'] ?? ''));

        /** @var Model $item */
        foreach ($items as $item) {
            // Nur, was noch offen ist. Ein zweites „erledigt" darf das erste
            // Datum nicht überschreiben — es ist der Zeitpunkt, an dem der
            // Vorgang bearbeitet wurde, nicht der letzte Klick.
            $item->newQuery()
                ->whereKey($item->getKey())
                ->whereNull('handled_at')
                ->update([
                    'handled_at' => Carbon::now(),
                    'handled_note' => $note !== '' ? $note : null,
                    'updated_at' => Carbon::now(),
                ]);
        }

        return trans_choice('statamic-payments::messages.legal_marked_handled', $items->count(), ['count' => $items->count()]);
    }
}
