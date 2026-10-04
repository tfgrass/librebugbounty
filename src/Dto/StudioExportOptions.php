<?php

namespace App\Dto;

final readonly class StudioExportOptions
{
    public const PROFILES = ['urls', 'state', 'report'];
    public const SCREENSHOT_MODES = ['basis', 'latest', 'all', 'none'];

    public function __construct(
        public FindingReadFilter $filter,
        public string $profile,
        public bool $includeRequestData,
        public bool $includeAssessment,
        public bool $includeContact,
        public bool $includePrivateNotes,
        public string $screenshotMode,
    ) {
        if (!in_array($profile, self::PROFILES, true)) {
            throw new \InvalidArgumentException('Unbekannte Exportvorlage.');
        }
        if (!in_array($screenshotMode, self::SCREENSHOT_MODES, true)) {
            throw new \InvalidArgumentException('Unbekannte Bildauswahl.');
        }
        if ($profile !== 'report' && $screenshotMode !== 'none') {
            throw new \InvalidArgumentException('Bilddateien sind nur im Meldungspaket verfügbar.');
        }
    }

    /** @return array<string, string> */
    public function query(): array
    {
        return [
            'profile' => $this->profile,
            'include_request_data' => $this->includeRequestData ? '1' : '0',
            'include_assessment' => $this->includeAssessment ? '1' : '0',
            'include_contact' => $this->includeContact ? '1' : '0',
            'include_notes' => $this->includePrivateNotes ? '1' : '0',
        ] + ($this->profile === 'report' ? ['screenshots' => $this->screenshotMode] : []);
    }
}
