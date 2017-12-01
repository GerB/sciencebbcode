<?php
namespace ger\sciencebbcode\helpers;

class chemrender
{

    /**
     * Format chemical formulas with sub-/superscript
     * 
     * @param string $text
     * @return string
     */
    static public function parse_chemcode($text)
    {
        // Replace arrows with pseudo-fontawsome -> processed later
        $text = str_replace("<->", '<fa_exchange/>', $text);
        $text = str_replace("->", '<fa_right/>', $text);

        // Split up in pieces, this allows for reactions 
        preg_match_all('/[A-Za-z]+(\)?[0-9]*,?\^?\)?[0-9]*[\+\-]?)/', $text, $matches, PREG_OFFSET_CAPTURE);
        $offset = 0;
        $new = $text;

        // Loop-ty-doo
        foreach ($matches[0] as $match) {
            $result = self::parse_single($match[0]);
            $new = substr($new, 0, $match[1] + $offset) . $result . substr($new, $match[1] + strlen($match[0]) + $offset);
            $offset += strlen($result) - strlen($match[0]);
        }
        return $new;
    }

    /**
     * Parse a single chemical part 
     */
    static public function parse_single($part)
    {
        $number = "";
        $parsed = "";

        for ($i = 0; $i < strlen($part);  ++$i) {
            $current = substr($part, $i, 1);
            if (is_numeric($current) || $current == '+' || $current == '-') {
                $number .= $current;
            } else if (strlen($number) > 0) {
                $parsed .= (is_numeric(substr($number, strlen($number) - 1))) ? "<sub>" . $number . "</sub>" : "<sup>" . $number . "</sup>";
                $parsed .= $current;
                $number = "";
            } else {
                $parsed .= $current;
            }
        }

        if (strlen($number) > 0) {
            $parsed .= (is_numeric(substr($number, strlen($number) - 1))) ? "<sub>" . $number . "</sub>" : "<sup>" . $number . "</sup>";
        }

        return preg_replace("/\^/", "", $parsed);
    }
}

// EoF