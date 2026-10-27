<?php

namespace App\Services;

use App\Enums\GpDevelopmentType;
use App\Enums\GpGeography;
use App\Enums\GpLeadStatus;
use App\Enums\GpProjectStage;
use App\Enums\GpSalesPriority;
use App\Models\FirstOrderDiamond;
use App\Models\GpPremisesSetting;

class GpPremisesNormalizer
{
    /**
     * @param  array<string, mixed>  $raw
     * @return array{summary: string, limitations: string}
     */
    public function summary(array $raw): array
    {
        $summary = is_array($raw['summary'] ?? null) ? $raw['summary'] : [];

        return [
            'summary' => $this->text($summary['geographic_coverage'] ?? null, 255).' '.trim($this->text($summary['source_period'] ?? null, 255)),
            'limitations' => $this->text($summary['limitations'] ?? null, 2000),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, GpDevelopmentType>  $allowedTypes
     * @return array<string, mixed>|null
     */
    public function lead(array $raw, GpPremisesSetting $settings, array $allowedTypes, bool $restrictGeography = true): ?array
    {
        if ($this->bool($raw['is_gp_practice'] ?? null) === false) {
            return null;
        }

        $surgery = $this->text($raw['surgery_name'] ?? null, 255);

        if ($surgery === '') {
            return null;
        }

        $nation = $this->nation($raw['nation'] ?? null);

        if ($nation === null || ($restrictGeography && $settings->geography !== GpGeography::UnitedKingdom && $nation !== $settings->geography)) {
            return null;
        }

        $type = $this->type($raw['development_type'] ?? null);

        if ($type === null || ! in_array($type, $allowedTypes, true)) {
            return null;
        }

        $stage = $this->stage($raw['project_stage'] ?? null);
        $url = FirstOrderDiamond::httpUrl($raw['source_url'] ?? null);
        $confirmed = $this->bool($raw['confirmed'] ?? null) === true;
        $status = GpLeadStatus::Active;

        if ($stage->isClosed()) {
            $status = GpLeadStatus::Inactive;
        } elseif (! $confirmed || $stage === GpProjectStage::Unknown || $url === null) {
            $status = GpLeadStatus::Watchlist;
        }

        $score = $this->score($raw['opportunity_score'] ?? null);
        $priority = $this->priority($raw['sales_priority'] ?? null, $score);

        return [
            'fingerprint' => $this->fingerprint($surgery, $type, $this->text($raw['town'] ?? null, 120)),
            'surgery_name' => $surgery,
            'website' => FirstOrderDiamond::httpUrl($raw['website'] ?? null),
            'town' => $this->text($raw['town'] ?? null, 120) ?: null,
            'county' => $this->text($raw['county'] ?? null, 120) ?: null,
            'nation' => $nation,
            'development_type' => $type,
            'development_details' => $this->text($raw['development_details'] ?? null, 2000) ?: null,
            'project_stage' => $stage,
            'stage_rank' => $stage->rank(),
            'status' => $status,
            'source_title' => $this->text($raw['source_title'] ?? null, 255) ?: null,
            'source_published_on' => $this->text($raw['source_published_on'] ?? null, 40) ?: null,
            'source_url' => $url,
            'project_dates' => $this->text($raw['project_dates'] ?? null, 255) ?: null,
            'funding' => $this->text($raw['funding'] ?? null, 255) ?: null,
            'planning_reference' => $this->text($raw['planning_reference'] ?? null, 120) ?: null,
            'planning_authority' => $this->text($raw['planning_authority'] ?? null, 160) ?: null,
            'source_confidence' => $this->confidence($raw['source_confidence'] ?? null),
            'fit_out_opportunity' => $this->text($raw['fit_out_opportunity'] ?? null, 2000) ?: null,
            'sales_timing' => $this->text($raw['sales_timing'] ?? null, 255) ?: null,
            'sales_priority' => $priority,
            'opportunity_score' => $score,
            'next_action' => $this->text($raw['next_action'] ?? null, 1000) ?: null,
            'summary' => $this->text($raw['summary'] ?? null, 2000) ?: null,
        ];
    }

    public function fingerprint(string $surgery, GpDevelopmentType $type, string $town): string
    {
        $normalize = fn (string $value): string => trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));

        return hash('sha256', $normalize($surgery).'|'.$type->value.'|'.$normalize($town));
    }

    private function nation(mixed $value): ?GpGeography
    {
        $text = strtolower($this->text($value, 80));

        return match (true) {
            str_contains($text, 'northern ireland') => GpGeography::NorthernIreland,
            str_contains($text, 'scotland') => GpGeography::Scotland,
            str_contains($text, 'wales') => GpGeography::Wales,
            str_contains($text, 'england') => GpGeography::England,
            default => null,
        };
    }

    private function type(mixed $value): ?GpDevelopmentType
    {
        $text = strtolower($this->text($value, 80));

        return match (true) {
            str_contains($text, 'reloc') => GpDevelopmentType::Relocation,
            str_contains($text, 'room') || str_contains($text, 'conversion') || str_contains($text, 'clinical') => GpDevelopmentType::ClinicalRooms,
            str_contains($text, 'extend') || str_contains($text, 'extension') => GpDevelopmentType::Extension,
            str_contains($text, 'refurb') => GpDevelopmentType::Refurbishment,
            str_contains($text, 'new') || str_contains($text, 'rebuild') || str_contains($text, 'replacement') => GpDevelopmentType::NewBuilding,
            default => GpDevelopmentType::tryFrom($text),
        };
    }

    private function stage(mixed $value): GpProjectStage
    {
        $text = strtolower($this->text($value, 80));

        return match (true) {
            str_contains($text, 'cancel') => GpProjectStage::Cancelled,
            str_contains($text, 'delay') || str_contains($text, 'postpone') => GpProjectStage::Delayed,
            str_contains($text, 'near') => GpProjectStage::NearingCompletion,
            str_contains($text, 'fit') => GpProjectStage::FitOut,
            str_contains($text, 'construct') || str_contains($text, 'underway') || str_contains($text, 'under way') => GpProjectStage::UnderConstruction,
            str_contains($text, 'procurement') || str_contains($text, 'tender') => GpProjectStage::Procurement,
            str_contains($text, 'fund') => GpProjectStage::FundingApproved,
            str_contains($text, 'planning_approved') || str_contains($text, 'permission') || str_contains($text, 'approved') => GpProjectStage::PlanningApproved,
            str_contains($text, 'planning') || str_contains($text, 'application') => GpProjectStage::PlanningSubmitted,
            str_contains($text, 'feasib') => GpProjectStage::Feasibility,
            str_contains($text, 'propos') || str_contains($text, 'consult') => GpProjectStage::Proposed,
            str_contains($text, 'complete') || str_contains($text, 'opened') => GpProjectStage::Completed,
            default => GpProjectStage::tryFrom($text) ?? GpProjectStage::Unknown,
        };
    }

    private function priority(mixed $value, int $score): GpSalesPriority
    {
        $text = strtolower($this->text($value, 20));
        $parsed = GpSalesPriority::tryFrom($text);

        if ($parsed !== null) {
            return $parsed;
        }

        return match (true) {
            $score >= 70 => GpSalesPriority::High,
            $score >= 40 => GpSalesPriority::Medium,
            default => GpSalesPriority::Low,
        };
    }

    private function confidence(mixed $value): string
    {
        $text = ucfirst(strtolower($this->text($value, 20)));

        return in_array($text, ['High', 'Medium', 'Low'], true) ? $text : 'Low';
    }

    private function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $text = strtolower(trim((string) $value));

        return match ($text) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => null,
        };
    }

    private function score(mixed $value): int
    {
        if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
            return 0;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function text(mixed $value, int $limit): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        return mb_substr(trim((string) $value), 0, $limit);
    }
}
