<?php

function extractTextFromPDF($filepath) {
    $content = file_get_contents($filepath);
    if ($content === false) return '';

    $text = '';

    // Method 1: Extract text from PDF stream objects
    // Find all stream...endstream blocks
    preg_match_all('/stream(.*?)endstream/s', $content, $streams);

    foreach ($streams[1] as $stream) {
        $stream = ltrim($stream, "\r\n");

        // Try zlib inflate (compressed streams)
        $inflated = @gzuncompress($stream);
        if ($inflated === false) {
            // Try raw inflate
            $inflated = @gzinflate($stream);
        }
        if ($inflated === false) {
            $inflated = $stream; // use raw if can't decompress
        }

        // Extract text from PDF operators
        // BT...ET blocks contain text
        preg_match_all('/BT(.*?)ET/s', $inflated, $textBlocks);
        foreach ($textBlocks[1] as $block) {
            // Tj operator: (text)Tj
            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/s',
                           $block, $tj);
            foreach ($tj[1] as $t) {
                $t = stripcslashes($t);
                $text .= $t . ' ';
            }
            // TJ operator: [(text)]TJ
            preg_match_all('/\[((?:[^\[\]]|\\\\.)*)\]\s*TJ/s',
                           $block, $tj2);
            foreach ($tj2[1] as $t) {
                preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/',
                               $t, $parts);
                foreach ($parts[1] as $p) {
                    $text .= stripcslashes($p);
                }
                $text .= ' ';
            }
            // Add newline after each text block
            $text .= "\n";
        }
    }

    // Method 2: Fallback - scan for readable ASCII strings
    // in the raw PDF (catches some uncompressed PDFs)
    if (strlen(trim($text)) < 100) {
        preg_match_all('/\(([\x20-\x7e]{4,})\)/', $content, $strings);
        $text = implode("\n", $strings[1]);
    }

    return $text;
}
