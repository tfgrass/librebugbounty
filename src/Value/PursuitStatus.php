<?php
namespace App\Value;

final class PursuitStatus
{
    public const REASONS = [
        'not_relevant' => 'Für mich nicht mehr relevant',
        'declined' => 'Empfänger hat kein Interesse',
        'contact_refused' => 'Keine weitere Kontaktaufnahme gewünscht',
        'checks_refused' => 'Keine weiteren Prüfungen gewünscht',
        'other' => 'Anderer Grund',
    ];
}
