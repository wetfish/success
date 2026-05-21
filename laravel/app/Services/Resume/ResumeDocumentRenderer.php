<?php

namespace App\Services\Resume;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;
use RuntimeException;

/**
 * Renders a structured resume spec into a .docx file via PhpWord.
 * The spec is produced by ResumeAiService::generateDocumentSpec()
 * — this class makes no content decisions, it only applies
 * typography, spacing, and layout.
 *
 * The output targets US Letter paper with professional resume
 * styling: clean sans-serif typography, tight spacing, and a
 * print-optimized layout that fits on 1-2 pages.
 */
class ResumeDocumentRenderer
{
    /** Reusable font style arrays. */
    private const FONT_NAME = [
        'name' => 'Arial',
        'size' => 10,
    ];

    private const FONT_HEADING = [
        'name' => 'Arial',
        'size' => 11,
        'bold' => true,
    ];

    private const FONT_SECTION = [
        'name' => 'Arial',
        'size' => 12,
        'bold' => true,
        'allCaps' => true,
    ];

    private const FONT_DATES = [
        'name' => 'Arial',
        'size' => 10,
        'italic' => true,
        'color' => '555555',
    ];

    private const FONT_BULLET = [
        'name' => 'Arial',
        'size' => 10,
    ];

    private const FONT_SMALL = [
        'name' => 'Arial',
        'size' => 9,
        'color' => '444444',
    ];

    /**
     * Render a document spec into a .docx file at the given path.
     *
     * @param  array  $spec  The structured spec from generateDocumentSpec().
     * @param  string  $outputPath  Absolute path for the output file.
     * @return int  File size in bytes.
     */
    public function render(array $spec, string $outputPath): int
    {
        $phpWord = new PhpWord();
        $this->configureDefaults($phpWord);

        $section = $phpWord->addSection([
            'marginTop' => 720,     // 0.5 inch
            'marginBottom' => 720,
            'marginLeft' => 1080,   // 0.75 inch
            'marginRight' => 1080,
            'pageSizeW' => 12240,   // US Letter width
            'pageSizeH' => 15840,   // US Letter height
        ]);

        $this->renderName($section, $spec['name'] ?? '{{NAME}}');
        $this->renderSummary($section, $spec['summary'] ?? '');
        $this->renderExperience($section, $spec['experience'] ?? []);
        $this->renderSkills($section, $spec['skills'] ?? []);
        $this->renderAdditional($section, $spec['additional'] ?? []);

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($outputPath);

        $size = filesize($outputPath);
        if ($size === false) {
            throw new RuntimeException("Failed to read file size: {$outputPath}");
        }

        return $size;
    }

    private function configureDefaults(PhpWord $phpWord): void
    {
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);

        $phpWord->addParagraphStyle('Normal', [
            'spaceAfter' => 60,
            'spaceBefore' => 0,
        ]);
    }

    private function renderName($section, string $name): void
    {
        $section->addText($name, [
            'name' => 'Arial',
            'size' => 20,
            'bold' => true,
        ], [
            'alignment' => Jc::CENTER,
            'spaceAfter' => 120,
        ]);
    }

    private function renderSummary($section, string $summary): void
    {
        if ($summary === '') {
            return;
        }

        $this->addSectionHeading($section, 'Professional Summary');

        $section->addText($summary, self::FONT_NAME, [
            'spaceAfter' => 200,
        ]);
    }

    private function renderExperience($section, array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        $this->addSectionHeading($section, 'Experience');

        foreach ($entries as $i => $entry) {
            $title = ($entry['title'] ?? '') . ', ' . ($entry['organization'] ?? '');

            $section->addText(trim($title, ', '), self::FONT_HEADING, [
                'spaceBefore' => $i > 0 ? 160 : 0,
                'spaceAfter' => 0,
            ]);

            if (! empty($entry['dates'])) {
                $section->addText($entry['dates'], self::FONT_DATES, [
                    'spaceAfter' => 60,
                ]);
            }

            foreach ($entry['bullets'] ?? [] as $bullet) {
                $section->addListItem($bullet, 0, self::FONT_BULLET, [
                    'spaceAfter' => 40,
                ], [
                    'listType' => \PhpOffice\PhpWord\Style\ListItem::TYPE_BULLET_FILLED,
                ]);
            }
        }
    }

    private function renderSkills($section, array $groups): void
    {
        if (empty($groups)) {
            return;
        }

        $this->addSectionHeading($section, 'Skills');

        foreach ($groups as $group) {
            $category = $group['category'] ?? '';
            $items = $group['items'] ?? [];

            if (empty($items)) {
                continue;
            }

            $textRun = $section->addTextRun([
                'spaceAfter' => 60,
            ]);

            if ($category !== '' && $category !== 'Skills') {
                $textRun->addText($category . ': ', [
                    'name' => 'Arial',
                    'size' => 10,
                    'bold' => true,
                ]);
            }

            $textRun->addText(implode(', ', $items), self::FONT_NAME);
        }
    }

    private function renderAdditional($section, array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            $heading = $entry['heading'] ?? 'Additional';
            $items = $entry['items'] ?? [];

            if (empty($items)) {
                continue;
            }

            $this->addSectionHeading($section, $heading);

            foreach ($items as $item) {
                $section->addListItem($item, 0, self::FONT_SMALL, [
                    'spaceAfter' => 40,
                ], [
                    'listType' => \PhpOffice\PhpWord\Style\ListItem::TYPE_BULLET_FILLED,
                ]);
            }
        }
    }

    /**
     * Add a section heading with a bottom border (thin rule line).
     */
    private function addSectionHeading($section, string $text): void
    {
        $section->addText($text, self::FONT_SECTION, [
            'spaceBefore' => 240,
            'spaceAfter' => 80,
            'borderBottomSize' => 6,
            'borderBottomColor' => '333333',
        ]);
    }
}