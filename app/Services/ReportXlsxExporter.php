<?php

namespace App\Services;

use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Collection;

class ReportXlsxExporter
{
    private string $sheet1Relationships = '';
    /**
     * Create a small, dependency-free XLSX workbook using the Open XML ZIP format.
     * Columns are [key, label, type?], where type is text, number, date, or duration.
     */
    public function download(string $filename, string $title, array $columns, iterable $rows, array $filters = [], array $summary = [])
    {
        $records = $rows instanceof Collection ? $rows->all() : iterator_to_array((function () use ($rows) { yield from $rows; })());
        $generatedAt = now();
        $dataRows = [];
        foreach ($records as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $value = data_get($row, $column['key']);
                $cells[] = ['value' => $value, 'type' => $column['type'] ?? 'text'];
            }
            $dataRows[] = $cells;
        }

        $summaryRows = [
            [['value' => 'Report', 'type' => 'text'], ['value' => $title, 'type' => 'text']],
            [['value' => 'Generated', 'type' => 'text'], ['value' => $generatedAt, 'type' => 'date']],
            [['value' => 'Rows', 'type' => 'text'], ['value' => count($records), 'type' => 'number']],
        ];
        foreach ($summary as $label => $value) {
            $summaryRows[] = [['value' => (string) $label, 'type' => 'text'], ['value' => $value, 'type' => 'text']];
        }
        $summaryRows[] = [['value' => '', 'type' => 'text']];
        $summaryRows[] = [['value' => 'Applied filters', 'type' => 'text'], ['value' => '', 'type' => 'text']];
        foreach ($filters as $label => $value) {
            if ($value === null || $value === '') $value = 'All';
            $summaryRows[] = [['value' => (string) $label, 'type' => 'text'], ['value' => $value, 'type' => 'text']];
        }

        $files = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => $this->rootRelationships(),
            'xl/workbook.xml' => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelationships(),
            'xl/styles.xml' => $this->styles(),
            'xl/worksheets/sheet1.xml' => $this->worksheet($title, $columns, $dataRows),
            'xl/worksheets/sheet2.xml' => $this->summaryWorksheet($summaryRows),
        ];
        if ($this->sheet1Relationships !== '') $files['xl/worksheets/_rels/sheet1.xml.rels'] = $this->sheet1Relationships;

        $content = $this->zip($files);
        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $filename).'"',
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Report Data" sheetId="1" r:id="rId1"/><sheet name="Summary" sheetId="2" r:id="rId2"/></sheets></workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3"><numFmt numFmtId="164" formatCode="[h]:mm:ss"/><numFmt numFmtId="165" formatCode="yyyy-mm-dd hh:mm"/><numFmt numFmtId="166" formatCode="mmm d, yyyy, h:mm AM/PM"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Aptos"/></font><font><b/><sz val="16"/><name val="Aptos Display"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE7F1EA"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FFD8E4DB"/></bottom><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="7"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/><xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function worksheet(string $title, array $columns, array $rows): string
    {
        if (collect($columns)->contains(fn ($column) => isset($column['group']))) {
            return $this->groupedWorksheet($title, $columns, $rows);
        }
        $this->sheet1Relationships = '';
        $hyperlinks = [];
        $relationships = [];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        $xml .= '<sheetData>'.$this->rowXml(1, [['value' => $title, 'type' => 'text']], 1)
            .$this->rowXml(2, [['value' => 'Generated: '.now()->format('Y-m-d H:i:s'), 'type' => 'text']], 2)
            .$this->rowXml(3, [['value' => count($rows).' report rows', 'type' => 'text']], 2)
            .$this->rowXml(5, array_map(fn ($column) => ['value' => $column['label'], 'type' => 'text'], $columns), 3);
        foreach ($rows as $index => $cells) {
            foreach ($cells as $cellIndex => $cell) {
                if (($cell['type'] ?? ($columns[$cellIndex]['type'] ?? 'text')) !== 'hyperlink' || empty($cell['value'])) continue;
                $reference = $this->columnName($cellIndex + 1).($index + 6);
                $relationshipId = 'rId'.(count($relationships) + 1);
                $url = (string) $cell['value'];
                if (!preg_match('/^https?:\/\//i', $url)) continue;
                $relationships[] = '<Relationship Id="'.$relationshipId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="'.$this->escape($url).'" TargetMode="External"/>';
                $hyperlinks[] = '<hyperlink ref="'.$reference.'" r:id="'.$relationshipId.'"/>';
            }
            $xml .= $this->rowXml($index + 6, $cells, 0, $columns);
        }
        if ($relationships) {
            $this->sheet1Relationships = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('', $relationships).'</Relationships>';
        }
        return $xml.'</sheetData>'.($hyperlinks ? '<hyperlinks xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.implode('', $hyperlinks).'</hyperlinks>' : '').'<autoFilter ref="A5:'.$this->columnName(max(1, count($columns))).(count($rows) + 5).'"/></worksheet>';
    }

    private function groupedWorksheet(string $title, array $columns, array $rows): string
    {
        $this->sheet1Relationships = '';
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        $widths = [];
        foreach ($columns as $index => $column) $widths[] = '<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.(float) ($column['width'] ?? 18).'" customWidth="1"/>';
        $xml .= '<cols>'.implode('', $widths).'</cols><sheetData>'
            .$this->rowXml(1, [['value' => $title, 'type' => 'text']], 1)
            .$this->rowXml(2, [['value' => 'Generated: '.now()->format('Y-m-d H:i:s'), 'type' => 'text']], 2)
            .$this->rowXml(3, [['value' => count($rows).' report rows', 'type' => 'text']], 2);
        $top = []; $bottom = []; $merges = [];
        for ($i = 0; $i < count($columns);) {
            if (!isset($columns[$i]['group'])) {
                $top[] = ['value' => $columns[$i]['label'], 'type' => 'text'];
                $bottom[] = ['value' => '', 'type' => 'text'];
                $col = $this->columnName($i + 1); $merges[] = $col.'5:'.$col.'6';
                $i++;
                continue;
            }
            $group = (string) $columns[$i]['group']; $start = $i + 1; $end = $start;
            while ($end < count($columns) && ($columns[$end]['group'] ?? null) === $group) $end++;
            $top[] = ['value' => $group, 'type' => 'text'];
            for ($j = $i; $j < $end; $j++) {
                if ($j > $i) $top[] = ['value' => '', 'type' => 'text'];
                $bottom[] = ['value' => $columns[$j]['label'], 'type' => 'text'];
            }
            if ($end - $i > 1) $merges[] = $this->columnName($start).'5:'.$this->columnName($end).'5';
            $i = $end;
        }
        $xml .= $this->rowXml(5, $top, 3).$this->rowXml(6, $bottom, 3);
        foreach ($rows as $index => $cells) $xml .= $this->rowXml($index + 7, $cells, 0, $columns);
        $lastColumn = $this->columnName(max(1, count($columns)));
        return $xml.'</sheetData><autoFilter ref="A6:'.$lastColumn.(count($rows) + 6).'"/><mergeCells count="'.count($merges).'">'
            .implode('', array_map(fn ($range) => '<mergeCell ref="'.$range.'"/>', $merges)).'</mergeCells></worksheet>';
    }

    private function summaryWorksheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $index => $row) $xml .= $this->rowXml($index + 1, $row);
        return $xml.'</sheetData></worksheet>';
    }

    private function rowXml(int $rowNumber, array $cells, int $rowStyle = 0, array $columns = []): string
    {
        $xml = '<row r="'.$rowNumber.'">';
        foreach (array_values($cells) as $index => $cell) {
            $value = $cell['value'] ?? null;
            $type = $cell['type'] ?? ($columns[$index]['type'] ?? 'text');
            $style = match ($type) { 'date' => 4, 'duration' => 5, 'datetime_readable' => 6, default => $rowStyle };
            $reference = $this->columnName($index + 1).$rowNumber;
            if ($type === 'number' && is_numeric($value)) {
                $xml .= '<c r="'.$reference.'"'.($style ? ' s="'.$style.'"' : '').'><v>'.(string) $value.'</v></c>';
            } elseif (in_array($type, ['date', 'datetime_readable'], true) && ($value instanceof DateTimeInterface || is_string($value) && trim($value) !== '')) {
                $date = $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : new DateTimeImmutable((string) $value);
                $base = new DateTimeImmutable('1899-12-30 00:00:00', $date->getTimezone() ?: new DateTimeZone('UTC'));
                $serial = ($date->getTimestamp() - $base->getTimestamp()) / 86400;
                $xml .= '<c r="'.$reference.'" s="'.$style.'"><v>'.number_format($serial, 8, '.', '').'</v></c>';
            } elseif ($type === 'duration' && is_numeric($value)) {
                $xml .= '<c r="'.$reference.'" s="5"><v>'.number_format(((float) $value) / 86400, 8, '.', '').'</v></c>';
            } else {
                $text = $value instanceof CarbonInterface ? $value->toDateTimeString() : (string) ($value ?? '');
                $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '';
                $xml .= '<c r="'.$reference.'" t="inlineStr"'.($style ? ' s="'.$style.'"' : '').'><is><t xml:space="preserve">'.$this->escape($text).'</t></is></c>';
            }
        }
        return $xml.'</row>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }
        return $name;
    }

    private function zip(array $files): string
    {
        $body = '';
        $central = '';
        $offset = 0;
        $dosTime = 0;
        $dosDate = 0x0021;
        foreach ($files as $name => $data) {
            $nameLength = strlen($name);
            $size = strlen($data);
            $crc = crc32($data);
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLength, 0).$name.$data;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset).$name;
            $body .= $local;
            $offset += strlen($local);
        }
        $count = count($files);
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($body), 0);
        return $body.$central.$end;
    }
}
