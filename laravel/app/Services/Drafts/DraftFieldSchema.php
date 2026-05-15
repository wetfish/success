<?php

namespace App\Services\Drafts;

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Http\Requests\PersonRules;
use App\Models\Link;

/**
 * Defines the editable field schema for each record type. The review
 * page uses this to render form inputs with appropriate types (text,
 * textarea, date, select, number, boolean) and to know which fields
 * the user must provide before confirmation can succeed.
 *
 * The schema is hand-maintained rather than derived from the models
 * because the AI's payload field names occasionally differ from the
 * model's column names (e.g., `organization_name` in the payload
 * resolves to `organization_id` on Position). The schema describes
 * the payload shape — what the user fills in — and the DraftConfirmer
 * handles the mapping to model columns.
 *
 * For each field:
 *   - type: 'text' | 'textarea' | 'date' | 'select' | 'number' |
 *           'boolean' | 'tag_list' | 'collaborator_list' | 'link_list'
 *   - required: true if the schema can't accept null/empty
 *   - options: for 'select' type, the allowed values
 *   - label: short human-readable label for the form
 *   - help: optional hint text below the input
 *
 * The 'tag_list', 'collaborator_list', and 'link_list' types describe
 * nested attachments on entity drafts (a project draft can carry a
 * list of tags, collaborators, and links that get materialized as
 * pivot or polymorphic rows when the draft is confirmed). The view
 * layer special-cases these — they're not scalar fields, so the
 * generic input rendering doesn't apply. See DraftConfirmer's
 * attachNestedTags, attachNestedCollaborators, and attachNestedLinks
 * for the materialization side.
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
            'person' => self::personFields(),
            'link' => self::linkFields(),
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
                // Sourced from the OrganizationType enum so the draft
                // review UI's accepted values stay in sync with the
                // rest of the application. Previously this list was
                // hand-maintained and had drifted from OrganizationRules
                // (had 'school' and 'community' that weren't valid,
                // missing 'personal', 'open_source', etc.) — pulling
                // from the enum prevents that recurring.
                'options' => array_column(OrganizationType::cases(), 'value'),
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
                'options' => array_column(OrganizationStatus::cases(), 'value'),
            ],
            'tags' => [
                'type' => 'tag_list',
                'label' => 'Tags',
                'required' => false,
                'help' => 'Tags the AI surfaced from the source document. Resolved against existing tag names and aliases on confirm; unknown tags auto-create.',
            ],
            'links' => [
                'type' => 'link_list',
                'label' => 'Links',
                'required' => false,
                'help' => 'URLs the AI extracted in connection with this organization. Each carries a type (website, careers, etc.) from the closed Link::TYPES enum.',
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
            'tags' => [
                'type' => 'tag_list',
                'label' => 'Tags',
                'required' => false,
                'help' => 'Tags the AI surfaced. Resolved against existing tag names and aliases on confirm.',
            ],
            'collaborators' => [
                'type' => 'collaborator_list',
                'label' => 'Collaborators',
                'required' => false,
                'help' => 'People mentioned in connection with this position. Each has a free-text role (e.g., "Manager", "Peer"). Unknown people auto-create.',
            ],
            'links' => [
                'type' => 'link_list',
                'label' => 'Links',
                'required' => false,
                'help' => 'URLs the AI extracted in connection with this position.',
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
            'tags' => [
                'type' => 'tag_list',
                'label' => 'Tags',
                'required' => false,
                'help' => 'Tags the AI surfaced. Resolved against existing tag names and aliases on confirm.',
            ],
            'collaborators' => [
                'type' => 'collaborator_list',
                'label' => 'Collaborators',
                'required' => false,
                'help' => 'People mentioned in connection with this project. Each has a free-text role (e.g., "Tech Lead", "Reviewer"). Unknown people auto-create.',
            ],
            'links' => [
                'type' => 'link_list',
                'label' => 'Links',
                'required' => false,
                'help' => 'URLs the AI extracted in connection with this project (source repos, live demos, docs, etc.).',
            ],
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
            'tags' => [
                'type' => 'tag_list',
                'label' => 'Tags',
                'required' => false,
                'help' => 'Tags the AI surfaced. Resolved against existing tag names and aliases on confirm.',
            ],
            'collaborators' => [
                'type' => 'collaborator_list',
                'label' => 'Collaborators',
                'required' => false,
                'help' => 'People mentioned in connection with this accomplishment. Each has a free-text role (e.g., "Co-author", "Reviewer"). Unknown people auto-create.',
            ],
            'links' => [
                'type' => 'link_list',
                'label' => 'Links',
                'required' => false,
                'help' => 'URLs the AI extracted in connection with this accomplishment (talk recordings, blog posts, postmortems, etc.).',
            ],
        ];
    }

    /**
     * Person fields are simpler than the entity types — no nested
     * tags or collaborators (people aren't taggable, and they don't
     * have collaborators on themselves). The schema covers only the
     * scalar Person fields plus the parent-by-name reference for the
     * current organization.
     */
    private static function personFields(): array
    {
        return [
            'name' => ['type' => 'text', 'label' => 'Name', 'required' => true],
            'current_title' => ['type' => 'text', 'label' => 'Current title', 'required' => false],
            'current_organization_name' => [
                'type' => 'text',
                'label' => 'Current organization',
                'required' => false,
                'help' => 'Optional — if set, must match the name of an existing organization.',
            ],
            'email' => ['type' => 'text', 'label' => 'Email', 'required' => false],
            'relationship_type' => [
                'type' => 'select',
                'label' => 'Relationship',
                'required' => false,
                'options' => PersonRules::RELATIONSHIP_TYPES,
            ],
            'user_notes' => ['type' => 'textarea', 'label' => 'Notes', 'required' => false],
        ];
    }

    /**
     * Link fields cover the polymorphic parent reference, the URL,
     * and the optional metadata. The `type` field shows the union of
     * all link types across all parent kinds — cross-field validity
     * (e.g., `github` is only valid on org/project parents, not on
     * positions) is enforced at the LinkRules validation layer, not
     * here. Keeping the schema permissive lets the review UI offer
     * the full type list as a select without per-row JS to filter
     * options when linkable_type changes.
     */
    private static function linkFields(): array
    {
        return [
            'linkable_type' => [
                'type' => 'select',
                'label' => 'Attaches to',
                'required' => true,
                'options' => ['organization', 'project', 'position', 'accomplishment'],
            ],
            'linkable_name' => [
                'type' => 'text',
                'label' => 'Entity name',
                'required' => true,
                'help' => 'The name of the entity this link attaches to. Must match an existing record of the chosen type.',
            ],
            'organization_name' => [
                'type' => 'text',
                'label' => 'Organization',
                'required' => false,
                'help' => 'Required when attaches-to is position, project, or accomplishment (to disambiguate the parent).',
            ],
            'url' => ['type' => 'text', 'label' => 'URL', 'required' => true],
            'type' => [
                'type' => 'select',
                'label' => 'Link type',
                'required' => false,
                // Union of all link types. Cross-field validity (e.g.,
                // github + position is invalid) is enforced at the
                // request validation layer when the link is created,
                // not here.
                'options' => Link::TYPES,
            ],
            'title' => ['type' => 'text', 'label' => 'Title', 'required' => false],
            'description' => ['type' => 'textarea', 'label' => 'Description', 'required' => false],
            'is_personal_appearance' => [
                'type' => 'boolean',
                'label' => 'Personal appearance',
                'required' => false,
                'help' => 'Signature evidence — a media appearance, conference talk, or podcast featuring you, as opposed to a supporting link like docs or a repo.',
            ],
            'date' => ['type' => 'date', 'label' => 'Date', 'required' => false],
        ];
    }
}