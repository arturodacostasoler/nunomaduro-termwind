<?php

declare(strict_types=1);

namespace Termwind\Html;

use DOMElement;
use DOMNode;
use Iterator;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\Components\Element;
use Termwind\Termwind;

/**
 * @internal
 */
final class TableRenderer
{
    /**
     * Symfony table object uses for table generation.
     */
    private Table $table;

    /**
     * This object is used for accumulating output data from Symfony table object and return it as a string.
     */
    private BufferedOutput $output;

    public function __construct(DOMNode $node)
    {
        $this->output = new BufferedOutput(
            // Content should output as is, without changes
            OutputInterface::VERBOSITY_NORMAL | OutputInterface::OUTPUT_RAW,
            true
        );

        $this->table = new Table($this->output);
        $this->parseTable($node);
    }

    /**
     * Converts table output to the content element.
     */
    public function toElement(): Element
    {
        $this->table->render();

        return Termwind::raw($this->output->fetch());
    }

    /**
     * Looks for thead, tfoot, tbody, tr elements in a given DOM and appends rows from them to the Symfony table object.
     */
    private function parseTable(DOMNode $node): void
    {
        /** @var DOMElement $node */
        $style = $node->getAttribute('style');
        if ($style !== '') {
            $this->table->setStyle($style);
        }

        foreach ($node->childNodes as $child) {
            match ($child->nodeName) {
                'thead' => $this->parseHeader($child),
                'tfoot' => $this->parseFoot($child),
                'tbody' => $this->parseBody($child),
                default => $this->parseRows($child)
            };
        }
    }

    /**
     * Looks for table header title and tr elements in a given thead DOM node and adds them to the Symfony table object.
     */
    private function parseHeader(DOMNode $node): void
    {
        /** @var DOMElement $node */
        $title = $node->getAttribute('title');

        if ($title !== '') {
            $this->table->getStyle()->setHeaderTitleFormat(
                $this->parseTitleStyle($node)
            );
            $this->table->setHeaderTitle($title);
        }

        foreach ($node->childNodes as $child) {
            if ($child->nodeName === 'tr') {
                foreach ($this->parseRow($child) as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $this->table->setHeaders($row);
                }
            }
        }
    }

    /**
     * Looks for table footer and tr elements in a given tfoot DOM node and adds them to the Symfony table object.
     */
    private function parseFoot(DOMNode $node): void
    {
        /** @var DOMElement $node */
        $title = $node->getAttribute('title');

        if ($title !== '') {
            $this->table->getStyle()->setFooterTitleFormat(
                $this->parseTitleStyle($node)
            );
            $this->table->setFooterTitle($title);
        }

        foreach ($node->childNodes as $child) {
            if ($child->nodeName === 'tr') {
                $rows = iterator_to_array($this->parseRow($child));
                if (count($rows) > 0) {
                    $this->table->addRow(new TableSeparator());
                    $this->table->addRows($rows);
                }
            }
        }
    }

    /**
     * Looks for tr elements in a given DOM node and adds them to the Symfony table object.
     */
    private function parseBody(DOMNode $node): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeName === 'tr') {
                $this->parseRows($child);
            }
        }
    }

    /**
     * Parses table tr elements.
     */
    private function parseRows(DOMNode $node): void
    {
        foreach ($this->parseRow($node) as $row) {
            $this->table->addRow($row);
        }
    }

    /**
     * Looks for th, td elements in a given DOM node and converts them to a table cells.
     *
     * @return Iterator<array<int, TableCell>|TableSeparator>
     */
    private function parseRow(DOMNode $node): Iterator
    {
        $row = [];

        foreach ($node->childNodes as $child) {
            /** @var DOMElement $child */
            if ($child->nodeName === 'th' || $child->nodeName === 'td') {
                $align = $child->getAttribute('align');

                $class = $child->getAttribute('class');
                if ($child->nodeName === 'th') {
                    $class .= ' font-bold';
                }

                $row[] = new TableCell(
                    // I need only spaces after applying margin, padding and width except tags.
                    // There is no place for tags, they broke cell formatting.
                    strip_tags((string) Termwind::span($child->nodeValue, $class)),
                    [
                        // Gets rowspan and colspan from tr and td tag attributes
                        'colspan' => max((int) $child->getAttribute('colspan'), 1),
                        'rowspan' => max((int) $child->getAttribute('rowspan'), 1),

                        // There are background and foreground and options
                        'style' => $this->parseCellStyle(
                            $class,
                            $align === '' ? TableCellStyle::DEFAULT_ALIGN : $align
                        ),
                    ]
                );
            }
        }

        if ($row !== []) {
            yield $row;
        }

        /** @var DOMElement $node */
        $border = (int) $node->getAttribute('border');
        for ($i = $border; $i--; $i > 0) {
            yield new TableSeparator();
        }
    }

    /**
     * Parses tr, td tag class attribute and passes bg, fg and options to a table cell style.
     */
    private function parseCellStyle(string $styles, string $align = TableCellStyle::DEFAULT_ALIGN): TableCellStyle
    {
        // I use this empty span for getting styles for bg, fg and options
        // It will be a good idea to get properties without element object and then pass them to an element object
        $element = Termwind::span('%s', $styles);

        $styles = [];

        $colors = $element->getProperties()['colors'] ?? [];

        foreach ($colors as $option => $content) {
            if (in_array($option, ['fg', 'bg'], true)) {
                $content = is_array($content) ? array_pop($content) : $content;

                $styles[] = "$option=$content";
            }
        }

        // If there are no styles we don't need extra tags
        if ($styles === []) {
            $cellFormat = '%s';
        } else {
            $cellFormat = '<'.implode(';', $styles).'>%s</>';
        }

        return new TableCellStyle([
            'align' => $align,
            'cellFormat' => $cellFormat,
        ]);
    }

    /**
     * Get styled representation of title.
     */
    private function parseTitleStyle(DOMElement $node): string
    {
        return (string) Termwind::span(' %s ', $node->getAttribute('class'));
    }
}