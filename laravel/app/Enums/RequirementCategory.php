<?php

namespace App\Enums;

/**
 * Classifies the type of a job listing requirement. Maps naturally
 * to resume sections and AI prompt instructions.
 *
 *   technical_skill  — a named technology (Python, Kubernetes, distributed systems)
 *   framework        — a specific framework (React, Laravel, Django)
 *   tool             — a product or platform (Stripe, AWS, Datadog, Kafka)
 *   experience       — a years/seniority qualifier (8+ years, senior IC)
 *   responsibility   — a job duty or deliverable (build fraud platform)
 *   domain_knowledge — industry or problem-space expertise (fraud detection, payments)
 *   soft_skill       — non-technical quality (collaborative influence, prioritization)
 *   credential       — degree or certification (CS degree, AWS certification)
 *   other            — catch-all for requirements that don't fit cleanly
 */
enum RequirementCategory: string
{
    case TechnicalSkill = 'technical_skill';
    case Framework = 'framework';
    case Tool = 'tool';
    case Experience = 'experience';
    case Responsibility = 'responsibility';
    case DomainKnowledge = 'domain_knowledge';
    case SoftSkill = 'soft_skill';
    case Credential = 'credential';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TechnicalSkill => 'Technical Skill',
            self::Framework => 'Framework',
            self::Tool => 'Tool',
            self::Experience => 'Experience',
            self::Responsibility => 'Responsibility',
            self::DomainKnowledge => 'Domain Knowledge',
            self::SoftSkill => 'Soft Skill',
            self::Credential => 'Credential',
            self::Other => 'Other',
        };
    }

    /**
     * String for inclusion in AI prompts — lists all accepted values
     * so the prompt stays in sync with the enum.
     */
    public static function promptEnumString(): string
    {
        return implode(' | ', array_map(
            fn (self $case) => "\"{$case->value}\"",
            self::cases(),
        ));
    }
}