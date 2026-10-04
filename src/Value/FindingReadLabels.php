<?php

namespace App\Value;

/** Shared wording for the independent dimensions of the finding read model. */
final class FindingReadLabels
{
    public static function assessment(?string $assessment, ?string $reason = null): string
    {
        return match ($assessment) {
            ManualAssessment::CONFIRMED => 'Befund bestätigt',
            ManualAssessment::FIXED => 'Behoben',
            ManualAssessment::DISCARDED => $reason === ManualAssessment::DUPLICATE ? 'Verworfen · Duplikat' : 'Verworfen',
            null => 'Keine aufgezeichnete manuelle Bewertung',
            default => $assessment,
        };
    }

    public static function observation(?string $result): string
    {
        return match ($result) {
            RetestResult::STILL_VULNERABLE => 'Nachweis (still_vulnerable)',
            RetestResult::FIXED => 'Kein Nachweis (fixed)',
            RetestResult::INCONCLUSIVE => 'Uneindeutig (inconclusive)',
            RetestResult::ERROR => 'Fehler (error)',
            RetestResult::PENDING => 'Ausstehend (pending)',
            null => 'Keine gespeicherte technische Beobachtung',
            default => $result,
        };
    }

    public static function contact(?\DateTimeImmutable $contactedAt): string
    {
        return $contactedAt === null ? 'Nicht kontaktiert' : 'Kontaktiert';
    }
}
