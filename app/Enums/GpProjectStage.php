<?php

namespace App\Enums;

enum GpProjectStage: string
{
    case Proposed = 'proposed';
    case Feasibility = 'feasibility';
    case PlanningSubmitted = 'planning_submitted';
    case PlanningApproved = 'planning_approved';
    case FundingApproved = 'funding_approved';
    case Procurement = 'procurement';
    case UnderConstruction = 'under_construction';
    case FitOut = 'fit_out';
    case NearingCompletion = 'nearing_completion';
    case Completed = 'completed';
    case Delayed = 'delayed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => __('Proposed'),
            self::Feasibility => __('Feasibility'),
            self::PlanningSubmitted => __('Planning submitted'),
            self::PlanningApproved => __('Planning approved'),
            self::FundingApproved => __('Funding approved'),
            self::Procurement => __('Procurement'),
            self::UnderConstruction => __('Under construction'),
            self::FitOut => __('Fit-out'),
            self::NearingCompletion => __('Nearing completion'),
            self::Completed => __('Completed'),
            self::Delayed => __('Delayed'),
            self::Cancelled => __('Cancelled'),
            self::Unknown => __('Unknown'),
        };
    }

    public function groupLabel(): string
    {
        return match ($this) {
            self::Proposed, self::Feasibility, self::PlanningSubmitted => __('Early plans'),
            self::PlanningApproved, self::FundingApproved, self::Procurement => __('Funding / permission'),
            self::UnderConstruction => __('Construction'),
            self::FitOut, self::NearingCompletion => __('Fit-out approaching'),
            self::Completed => __('Recently completed'),
            self::Delayed => __('Delayed'),
            self::Cancelled => __('Cancelled'),
            self::Unknown => __('Needs verification'),
        };
    }

    /**
     * Lower numbers are closer to a furniture purchasing window.
     */
    public function rank(): int
    {
        return match ($this) {
            self::FitOut, self::NearingCompletion => 1,
            self::FundingApproved, self::PlanningApproved, self::Procurement => 2,
            self::Proposed, self::Feasibility, self::PlanningSubmitted => 3,
            self::UnderConstruction => 4,
            self::Delayed => 5,
            default => 6,
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }
}
