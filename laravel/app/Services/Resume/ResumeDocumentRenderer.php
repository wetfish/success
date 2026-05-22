<?php

namespace App\Services\Resume;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\ListItem;
use RuntimeException;

/**
 * Renders a structured resume spec into a .docx file via PhpWord.
 * The spec is produced by ResumeAiService::generateDocumentSpec()
 * — this class makes no content decisions, it only applies
 * typography, spacing, and layout.
 *
 * Font and color choices come from the spec's "styling" key, which
 * the AI populates based on the user's style guidelines. When no
 * styling is specified, sensible defaults (Arial, dark grays) apply.
 *
 * All text is passed through addText() or addListItemRun()->addText()
 * rather than addListItem() directly, because addText() properly
 * escapes XML entities (&, <, >, em-dashes, etc.) while addListItem()
 * can produce corrupt XML with special characters.
 */
class ResumeDocumentRenderer
{
    /** Resolved styling — set from spec in render(). */
    private string $font = 'Arial';
    private string $colorHeading = '333333';
    private string $colorAccent = '333333';
    private string $colorBody = '444444';

    /**
     * Render a document spec into a .docx file at the given path.
     *
     * @param  array  $spec  The structured spec from generateDocumentSpec().
     * @param  string  $outputPath  Absolute path for the output file.
     * @param  array  $contactInfo  Header fields: name (required), title, email, phone, location.
     * @return int  File size in bytes.
     */
    public function render(array $spec, string $outputPath, array $contactInfo = []): int
    {
        $this->applyStylesFromSpec($spec['styling'] ?? []);

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

        $this->renderHeader($section, $contactInfo);
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

    private function applyStylesFromSpec(array $styling): void
    {
        $this->font = $styling['font_primary'] ?? 'Arial';
        $this->colorHeading = $styling['color_heading'] ?? '333333';
        $this->colorAccent = $styling['color_accent'] ?? '333333';
        $this->colorBody = $styling['color_body'] ?? '444444';
    }

    private function fontBody(array $overrides = []): array
    {
        return array_merge(['name' => $this->font, 'size' => 10], $overrides);
    }

    /**
     * Sanitize text for XML safety. PhpWord's internal escaping
     * misses some cases (bare & in certain code paths, edge-case
     * Unicode). This catches bare & that aren't already part of
     * valid XML entities, preventing xmlParseEntityRef errors.
     */
    private function clean(string $text): string
    {
        // Replace em-dashes and en-dashes with regular hyphens.
        // These cause XML entity issues in some PhpWord code paths.
        $text = str_replace(["\u{2014}", "\u{2013}"], '-', $text);

        // Replace bare & not followed by a valid XML entity pattern.
        // Won't double-escape existing &amp; &lt; &gt; &#123; &#xAB; etc.
        $text = preg_replace('/&(?![a-zA-Z0-9#]+;)/', '&amp;', $text);

        // Strip XML-illegal control characters (0x00-0x08, 0x0B, 0x0C, 0x0E-0x1F)
        // that occasionally appear in AI output or pasted text.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

        return $text;
    }

    private function configureDefaults(PhpWord $phpWord): void
    {
        $phpWord->setDefaultFontName($this->font);
        $phpWord->setDefaultFontSize(10);

        $phpWord->addParagraphStyle('Normal', [
            'spaceAfter' => 60,
            'spaceBefore' => 0,
        ]);
    }

    private function renderHeader($section, array $contactInfo): void
    {
        $name = $contactInfo['name'] ?? '{{NAME}}';

        $section->addText($this->clean($name), [
            'name' => $this->font,
            'size' => 20,
            'bold' => true,
            'color' => $this->colorHeading,
        ], [
            'alignment' => Jc::CENTER,
            'spaceAfter' => 0,
        ]);

        // Professional title line.
        if (! empty($contactInfo['title'])) {
            $section->addText($this->clean($contactInfo['title']), $this->fontBody([
                'size' => 11,
                'color' => $this->colorBody,
            ]), [
                'alignment' => Jc::CENTER,
                'spaceAfter' => 0,
            ]);
        }

        // Contact details line: location, email, phone joined by separator.
        $details = array_filter([
            $contactInfo['location'] ?? null,
            $contactInfo['email'] ?? null,
            $contactInfo['phone'] ?? null,
        ]);

        if (! empty($details)) {
            $section->addText(
                $this->clean(implode('  |  ', $details)),
                $this->fontBody(['size' => 9, 'color' => $this->colorBody]),
                ['alignment' => Jc::CENTER, 'spaceAfter' => 120],
            );
        }
    }

    private function renderSummary($section, string $summary): void
    {
        if ($summary === '') {
            return;
        }

        $this->addSectionHeading($section, 'Professional Summary');

        $section->addText($this->clean($summary), $this->fontBody(), [
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

            $section->addText($this->clean(trim($title, ', ')), $this->fontBody([
                'size' => 11,
                'bold' => true,
                'color' => $this->colorHeading,
            ]), [
                'spaceBefore' => $i > 0 ? 160 : 0,
                'spaceAfter' => 0,
            ]);

            if (! empty($entry['dates'])) {
                $section->addText($this->clean($entry['dates']), $this->fontBody([
                    'italic' => true,
                    'color' => '555555',
                ]), [
                    'spaceAfter' => 60,
                ]);
            }

            foreach ($entry['bullets'] ?? [] as $bullet) {
                $this->addBulletItem($section, $bullet, $this->fontBody());
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
                $textRun->addText($this->clean($category) . ': ', $this->fontBody([
                    'bold' => true,
                ]));
            }

            $textRun->addText($this->clean(implode(', ', $items)), $this->fontBody());
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
                $this->addBulletItem($section, $item, $this->fontBody([
                    'size' => 9,
                    'color' => $this->colorBody,
                ]));
            }
        }
    }

    /**
     * Add a bullet list item using addListItemRun() + addText().
     * This path properly escapes XML entities (& < > — etc.),
     * unlike addListItem() which can produce corrupt XML.
     */
    private function addBulletItem($section, string $text, array $fontStyle): void
    {
        $listItemRun = $section->addListItemRun(0, [
            'listType' => ListItem::TYPE_BULLET_FILLED,
        ], [
            'spaceAfter' => 40,
        ]);
        $listItemRun->addText($this->clean($text), $fontStyle);
    }

    /**
     * Add a section heading with a bottom border using the accent color.
     */
    private function addSectionHeading($section, string $text): void
    {
        $section->addText($this->clean($text), [
            'name' => $this->font,
            'size' => 12,
            'bold' => true,
            'allCaps' => true,
            'color' => $this->colorAccent,
        ], [
            'spaceBefore' => 240,
            'spaceAfter' => 80,
            'borderBottomSize' => 6,
            'borderBottomColor' => $this->colorAccent,
        ]);
    }
}