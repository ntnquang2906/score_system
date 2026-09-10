<?php
// Ghi file .xlsx (Excel thật, không phải text đổi đuôi) từ một mảng hàng dữ
// liệu, không cần ext-zip/Composer nào cả — .xlsx vốn là 1 file ZIP chứa các
// XML bên trong, nên hàm dưới đây tự dựng cấu trúc ZIP (kiểu "stored", không
// nén) bằng các hàm PHP lõi (crc32, pack) thay vì dùng ZipArchive.
//
// Do dùng "inline string" (t="inlineStr") cho mọi ô nên không cần bảng
// sharedStrings.xml, và vì chuỗi được ghi thẳng dạng UTF-8 trong XML nên
// không còn phụ thuộc vào việc Excel "đoán" bảng mã như file .tsv/.csv nữa.

function xlsxEscapeText($text)
{
    $text = (string) $text;
    $text = str_replace(
        ['&', '<', '>', '"', "'"],
        ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
        $text
    );

    // Loại các ký tự điều khiển không hợp lệ trong XML 1.0 (trừ tab/xuống dòng).
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
}

function xlsxColumnLetter($index)
{
    $letters = '';
    $index++;

    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $index = intdiv($index - 1, 26);
    }

    return $letters;
}

function xlsxBuildSheetXml($rows)
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<sheetData>';

    foreach ($rows as $rowIndex => $row) {
        $rowNum = $rowIndex + 1;
        $xml .= '<row r="' . $rowNum . '">';

        foreach ($row as $colIndex => $cell) {
            $ref = xlsxColumnLetter($colIndex) . $rowNum;
            $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' .
                xlsxEscapeText($cell) . '</t></is></c>';
        }

        $xml .= '</row>';
    }

    $xml .= '</sheetData></worksheet>';

    return $xml;
}

// Cắt bớt chuỗi UTF-8 theo số byte tối đa mà không làm hỏng ký tự đa byte ở
// cuối (dự án này không có sẵn extension mbstring nên không dùng mb_substr).
function xlsxTruncateUtf8Bytes($text, $maxBytes)
{
    if (strlen($text) <= $maxBytes) {
        return $text;
    }

    $text = substr($text, 0, $maxBytes);

    while ($text !== '' && (ord(substr($text, -1)) & 0xC0) === 0x80) {
        $text = substr($text, 0, -1);
    }

    return $text;
}

function xlsxBuildParts($sheetName, $rows)
{
    // Excel giới hạn tên sheet tối đa 31 ký tự; cắt theo byte cho an toàn
    // (không có mbstring), thừa an toàn hơn là cắt đúng 31 ký tự.
    $safeSheetName = xlsxEscapeText(xlsxTruncateUtf8Bytes($sheetName, 31));

    return [
        '[Content_Types].xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '</Types>',

        '_rels/.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>',

        'xl/workbook.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets><sheet name="' . $safeSheetName . '" sheetId="1" r:id="rId1"/></sheets>' .
            '</workbook>',

        'xl/_rels/workbook.xml.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '</Relationships>',

        'xl/worksheets/sheet1.xml' => xlsxBuildSheetXml($rows),
    ];
}

// Đóng gói các phần XML trên thành 1 file ZIP hợp lệ (không nén / "stored"),
// theo đúng cấu trúc PKZIP: local file header -> dữ liệu -> (lặp lại) ->
// central directory -> end of central directory record.
function xlsxZipArchive($parts)
{
    $body = '';
    $centralDirectory = '';
    $offset = 0;
    $count = 0;

    foreach ($parts as $name => $content) {
        $crc = crc32($content);
        $size = strlen($content);
        $dosTime = 0;
        $dosDate = (1 << 5) | 1; // 1980-01-01, đủ để ZIP hợp lệ, không cần chính xác

        $localHeader = "PK\x03\x04"
            . pack('v', 20)      // version needed
            . pack('v', 0)       // flags
            . pack('v', 0)       // method: 0 = stored
            . pack('v', $dosTime)
            . pack('v', $dosDate)
            . pack('V', $crc)
            . pack('V', $size)   // compressed size
            . pack('V', $size)   // uncompressed size
            . pack('v', strlen($name))
            . pack('v', 0)       // extra field length
            . $name;

        $body .= $localHeader . $content;

        $centralDirectory .= "PK\x01\x02"
            . pack('v', 20)      // version made by
            . pack('v', 20)      // version needed
            . pack('v', 0)       // flags
            . pack('v', 0)       // method
            . pack('v', $dosTime)
            . pack('v', $dosDate)
            . pack('V', $crc)
            . pack('V', $size)
            . pack('V', $size)
            . pack('v', strlen($name))
            . pack('v', 0)       // extra field length
            . pack('v', 0)       // comment length
            . pack('v', 0)       // disk number start
            . pack('v', 0)       // internal attributes
            . pack('V', 0)       // external attributes
            . pack('V', $offset) // offset of local header
            . $name;

        $offset += strlen($localHeader) + $size;
        $count++;
    }

    $centralDirOffset = strlen($body);
    $centralDirSize = strlen($centralDirectory);

    $eocd = "PK\x05\x06"
        . pack('v', 0)          // disk number
        . pack('v', 0)          // disk with central dir
        . pack('v', $count)     // entries on this disk
        . pack('v', $count)     // total entries
        . pack('V', $centralDirSize)
        . pack('V', $centralDirOffset)
        . pack('v', 0);         // comment length

    return $body . $centralDirectory . $eocd;
}

// Ghi 1 file .xlsx (1 sheet) từ mảng $rows (mỗi phần tử là 1 mảng ô dạng chuỗi).
function writeXlsxFile($filepath, $sheetName, $rows)
{
    $parts = xlsxBuildParts($sheetName, $rows);
    $zip = xlsxZipArchive($parts);

    return file_put_contents($filepath, $zip) !== false;
}
