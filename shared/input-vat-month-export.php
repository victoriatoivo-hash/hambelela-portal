<?php
declare(strict_types=1);

function iv_export_period(string $month): array
{
    if (!preg_match('/^(\d{4})-(\d{2})$/D', $month, $m) || (int)$m[1] < 1900 || (int)$m[1] > 9998 || !checkdate((int)$m[2], 1, (int)$m[1])) {
        throw new InvalidArgumentException('Select a valid Input VAT month before exporting.');
    }
    $start = new DateTimeImmutable($month.'-01', new DateTimeZone('Africa/Windhoek'));
    return [$start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d'), $start->format('F Y')];
}

function iv_export_rows(PDO $pdo, string $month): array
{
    [$from, $until] = iv_export_period($month);
    // Export the complete displayed month, not the first page or another month.
    // Values are saved accounting figures; no VAT recalculation or writes.
    $query = $pdo->prepare('SELECT purchase_date, supplier, invoice_reference, description, amount_incl_vat, vat_amount, amount_excl_vat, vat_treatment, COALESCE(vat_rate_used, vat_rate) AS vat_rate, review_status, created_by_name, created_at FROM accounts_input_vat_purchases WHERE deleted_at IS NULL AND purchase_date >= ? AND purchase_date < ? ORDER BY purchase_date ASC, id ASC');
    $query->execute([$from, $until]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function iv_export_xml(string $text): string
{
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '';
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function iv_export_xlsx(string $month, array $rows): string
{
    [, , $label] = iv_export_period($month);
    if (!class_exists('ZipArchive')) throw new RuntimeException('Excel export is unavailable. Please contact the portal administrator.');
    $path = tempnam(sys_get_temp_dir(), 'iv-export-');
    if ($path === false) throw new RuntimeException('Unable to prepare the Excel download.');
    $zip = new ZipArchive();
    try {
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to create the Excel workbook.');
        $cell = static function(string $ref, $value, int $style = 0, bool $number = false): string {
            return '<c r="'.$ref.'" s="'.$style.'"'.($number ? '><v>'.iv_export_xml((string)$value).'</v>' : ' t="inlineStr"><is><t xml:space="preserve">'.iv_export_xml((string)$value).'</t></is>').'</c>';
        };
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/><cols>';
        foreach ([15,28,23,50,19,19,19,23,13,22,26,24] as $i => $width) $xml .= '<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>';
        $xml .= '</cols><sheetData><row r="1" ht="28" customHeight="1">'.$cell('A1','Hambelela Organic · Input VAT',1).'</row>';
        $xml .= '<row r="2">'.$cell('A2',$label.' · '.count($rows).' purchase entries',2).'</row>';
        $xml .= '<row r="3">'.$cell('A3','Currency: NAD (N$) · Period is based on purchase date · All saved monthly entries',2).'</row><row r="5" ht="26" customHeight="1">';
        foreach (['Purchase date','Supplier','Invoice / Reference','Description','Amount incl. VAT (N$)','Input VAT (N$)','Amount excl. VAT (N$)','VAT treatment','VAT rate','Status','Entered by','Captured at · Namibia'] as $i=>$heading) $xml .= $cell(chr(65+$i).'5',$heading,3);
        $xml .= '</row>';
        $totals = [0,0,0];
        $r = 6;
        foreach ($rows as $row) {
            if (substr((string)$row['purchase_date'],0,7) !== $month) throw new RuntimeException('Export contains a date outside the selected month.');
            $xml .= '<row r="'.$r.'" ht="32" customHeight="1">';
            $date = new DateTimeImmutable($row['purchase_date'],new DateTimeZone('UTC'));
            $values = [(int)floor($date->getTimestamp()/86400)+25569,$row['supplier'],$row['invoice_reference'] ?? '',$row['description'],
                $row['amount_incl_vat'],$row['vat_amount'],$row['amount_excl_vat'],ucwords(str_replace('_',' ',$row['vat_treatment'])),
                (float)$row['vat_rate']/100,ucwords(str_replace('_',' ',$row['review_status'])),$row['created_by_name'],$row['created_at']];
            foreach ($values as $i=>$v) {
                $numeric = $i===0 || in_array($i,[4,5,6,8],true);
                if ($numeric && !is_numeric($v)) throw new RuntimeException('A saved amount is invalid. Please review the monthly records.');
                $style = $i===0 ? 5 : ($i===8 ? 6 : (in_array($i,[4,5,6],true) ? 4 : 0));
                $xml .= $cell(chr(65+$i).$r,$v,$style,$numeric);
                if ($i>=4 && $i<=6) $totals[$i-4] += (int)round((float)$v*100);
            }
            $xml .= '</row>'; $r++;
        }
        $xml .= '<row r="'.$r.'" ht="24" customHeight="1">'.$cell('A'.$r,'Month totals',7);
        foreach ($totals as $i=>$value) {
            $col=chr(69+$i);
            $xml.='<c r="'.$col.$r.'" s="8"><f>'.($rows ? 'SUM('.$col.'6:'.$col.($r-1).')' : '0').'</f><v>'.number_format($value/100,2,'.','').'</v></c>';
        }
        $xml .= '</row></sheetData><autoFilter ref="A5:L'.max(5,$r-1).'"/><mergeCells count="3"><mergeCell ref="A1:L1"/><mergeCell ref="A2:L2"/><mergeCell ref="A3:L3"/></mergeCells><pageMargins left="0.3" right="0.3" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/></worksheet>';
        $entries = [
            '[Content_Types].xml'=>'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml'=>'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Input VAT '.$month.'" sheetId="1" r:id="rId1"/></sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>',
            'xl/_rels/workbook.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml'=>'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="164" formatCode="&quot;N$ &quot;#,##0.00;[Red](&quot;N$ &quot;#,##0.00)"/><numFmt numFmtId="165" formatCode="dd mmm yyyy"/></numFmts><fonts count="3"><font><sz val="11"/><name val="Jost"/><color rgb="FF344126"/></font><font><b/><sz val="18"/><name val="Jost"/><color rgb="FF344126"/></font><font><b/><sz val="11"/><name val="Jost"/><color rgb="FFFFFFFF"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF59694A"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF2F4EC"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="9"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="center"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0"/><xf numFmtId="164" fontId="0" fillId="3" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml'=>$xml,
        ];
        foreach ($entries as $name=>$content) if (!$zip->addFromString($name,$content)) throw new RuntimeException('Unable to write the Excel workbook.');
        if (!$zip->close()) throw new RuntimeException('Unable to finish the Excel workbook.');
        return $path;
    } catch (Throwable $error) {
        try { $zip->close(); } catch (Throwable $ignored) {}
        @unlink($path);
        throw $error;
    }
}
