<?php

namespace App\Services\Drafts;

/**
 * Defines the editable field schema for each record type. The review
 * page uses this to render form inputs with appropriate types (text,
 * textarea, date, select, number) and to know which fields the user
 * must provide before confirmation can succeed.
 *
 * The schema is hand-maintained rather than derived from the models
 * because the AI's payload field names occasionally differ from the
 * model's column names (e.g., `organization_name` in the payload
 * resolves to `organization_id` on Position). The schema describes
 * the payload shape — what the user fills in — and the DraftConfirmer
 * handles the mapping to model columns.
 *
 * For each field:
 *   - type: 'text' | 'textarea' | 'date' | 'select' | 'number'
 *   - required: true if the schema can't accept null/empty
 *   - options: for 'select' type, the allowed values
 *   - label: short human-readable label for the form
 *   - help: optional hint text below the input
 */
class DraftFieldSchema
{
    public static function for(string $recordType): array
    {
        return match ($recordType) {
            'organization' => self::organizationFields(),
            'position' => self::positionFields(),
            'project' => self::projectFields(),
            'accomplishment' => self::accomplishmentFields(),
            default => [],
        };
    }

    private static function organizationFields(): array
    {
        return [
            'name' => ['type' => 'text', 'label' => 'Name', 'required' => true],
            'type' => [
                'type' => 'select',
                'label' => 'Type',
                'required' => true,
                'options' => ['employer', 'client', 'school', 'community', 'other'],
            ],
            'website' => ['type' => 'text', 'label' => 'Website', 'required' => false],
            'tagline' => ['type' => 'text', 'label' => 'Tagline', 'required' => false],
            'description' => ['type' => 'textarea', 'label' => 'Description', 'required' => false],
            'headquarters' => ['type' => 'text', 'label' => 'Headquarters', 'required' => false],
            'founded_year' => ['type' => 'number', 'label' => 'Founded year', 'required' => false],
            'size_estimate' => ['type' => 'text', 'label' => 'Size estimate', 'required' => false],
            'status' => [
                'type' => 'select',
                'label' => 'Status',
                'required' => false,
                'options' => ['active', 'acquired', 'shut_down', 'unknown'],
            ],
        ];
    }

    private static function positionFields(): array
    {
        return [
            'organization_name' => [
                'type' => 'text', 'label' => 'Organization',
                'required' => true,
                'help' => 'Must match the name of an existing organization.',
            ],
            'title' => ['type' => 'text', 'label' => 'Title', 'required' => true],
            'employment_type' => [
                'type' => 'select',
                'label' => 'Employment type',
                'required' => true,
                'options' => [
                    'full_time', 'part_time', 'contract', 'freelance',
                    'internship', 'advisor', 'volunteer', 'founder',
                ],
            ],
            'location_arrangement' => [
                'type' => 'select',
                'label' => 'Location arrangement',
                'required' => true,
                'options' => ['remote', 'hybrid', 'on_site'],
            ],
            'start_date' => ['type' => 'date', 'label' => 'Start date', 'required' => true],
            'end_date' => [
                'type' => 'date', 'label' => 'End date', 'required' => false,
                'help' => 'Leave empty if current.',
            ],
            'location_text' => ['type' => 'text', 'label' => 'Location', 'required' => false],
            'team_name' => ['type' => 'text', 'label' => 'Team', 'required' => false],
            'team_size_immediate' => ['type' => 'number', 'label' => 'Immediate team size', 'required' => false],
            'team_size_extended' => ['type' => 'number', 'label' => 'Extended team size', 'required' => false],
            'mandate' => ['type' => 'textarea', 'label' => 'Mandate', 'required' => false],
            'reason_for_leaving' => [
                'type' => 'select',
                'label' => 'Reason for leaving',
                'required' => false,
                'options' => [
                    'still_employed', 'laid_off', 'quit_for_opportunity',
                    'quit_for_personal', 'contract_ended', 'company_wound_down',
                    'terminated', 'other',
                ],
            ],
        ];
    }

    private static function projectFields(): array
    {
        return [
            'organization_name' => [
                'type' => 'text', 'label' => 'Organization',
                'required' => true,
                'help' => 'Must match the name of an existing organization.',
            ],
            'position_title' => [
                'type' => 'text', 'label' => 'Position',
                'required' => false,
                'help' => 'Optional — title of a position at that organization.',
            ],
            'parent_project_name' => [
                'type' => 'text', 'label' => 'Parent project',
                'required' => false,
                'help' => 'Optional — name of a parent project at the same organization.',
            ],
            'name' => ['type' => 'text', 'label' => 'Name', 'required' => true],
            'description' => ['type' => 'textarea', 'label' => 'Description', 'required' => false],
            'visibility' => [
                'type' => 'select',
                'label' => 'Visibility',
                'required' => true,
                'options' => ['public', 'open_source', 'internal', 'confidential'],
            ],
            'contribution_level' => [
                'type' => 'select',
                'label' => 'Contribution level',
                'required' => true,
                'options' => ['lead', 'core', 'contributor', 'occasional', 'reviewer'],
            ],
            'status' => [
                'type' => 'select',
                'label' => 'Status',
                'required' => false,
                'options' => ['live', 'archived', 'killed', 'prototype', 'ongoing'],
            ],
            'public_name' => ['type' => 'text', 'label' => 'Public name', 'required' => false],
            'problem' => ['type' => 'textarea', 'label' => 'Problem', 'required' => false],
            'constraints' => ['type' => 'textarea', 'label' => 'Constraints', 'required' => false],
            'approach' => ['type' => 'textarea', 'label' => 'Approach', 'required' => false],
            'outcome' => ['type' => 'textarea', 'label' => 'Outcome', 'required' => false],
            'rationale' => ['type' => 'textarea', 'label' => 'Rationale', 'required' => false],
            'date_precision' => [
                'type' => 'select',
                'label' => 'Date precision',
                'required' => false,
                'options' => ['day', 'month', 'quarter', 'year'],
            ],
            'start_date' => ['type' => 'date', 'label' => 'Start date', 'required' => false],
            'end_date' => ['type' => 'date', 'label' => 'End date', 'required' => false],
            'contribution_type' => ['type' => 'text', 'label' => 'Contribution type', 'required' => false],
            'team_size' => ['type' => 'number', 'label' => 'Team size', 'required' => false],
        ];
    }

    private static function accomplishmentFields(): array
    {
        return [
            'organization_name' => [
                'type' => 'text', 'label' => 'Organization',
                'required' => true,
                'help' => 'Must match the name of an existing organization.',
            ],
            'project_name' => [
                'type' => 'text', 'label' => 'Project',
                'required' => false,
                'help' => 'Either project or position (not both) is required.',
            ],
            'position_title' => [
                'type' => 'text', 'label' => 'Position',
                'required' => false,
                'help' => 'Either project or position (not both) is required.',
            ],
            'title' => ['type' => 'text', 'label' => 'Title', 'required' => true],
            'description' => ['type' => 'textarea', 'label' => 'Description', 'required' => true],
            'date' => [
                'type' => 'date', 'label' => 'Date',
                'required' => false,
                'help' => 'Use this for a point-in-time accomplishment.',
            ],
            'period_start' => [
                'type' => 'date', 'label' => 'Period start',
                'required' => false,
                'help' => 'Use this (and optionally period end) for a sustained accomplishment.',
            ],
            'period_end' => ['type' => 'date', 'label' => 'Period end', 'required' => false],
            'impact_metric' => ['type' => 'text', 'label' => 'Impact metric', 'required' => false],
            'impact_value' => ['type' => 'text', 'label' => 'Impact value', 'required' => false],
            'impact_unit' => ['type' => 'text', 'label' => 'Impact unit', 'required' => false],
            'confidence' => [
                'type' => 'number', 'label' => 'Confidence (1-5)',
                'required' => false,
                'help' => 'How well does the source evidence support this?',
            ],
            'prominence' => [
                'type' => 'number', 'label' => 'Prominence (1-5)',
                'required' => false,
                'help' => 'How significant is this accomplishment in context?',
            ],
        ];
    }
}