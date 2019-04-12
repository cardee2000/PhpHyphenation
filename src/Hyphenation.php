<?php

namespace Hyphenation;

use MifestaFileSystem\FileSystem;

/**
 * Class Hyphenation
 * Arrangements of hyphenation in words
 * Arrangements of hyphenation in words
 * @package Hyphenation
 */
class Hyphenation
{
    /************************************************************
     * MAIN CONSTANTS                                           *
     ************************************************************/
    const VERSION = '1.0.3';
    const AUTO_RECOMPILE = 0;
    const NEVER_RECOMPILE = 1;
    const ALWAYS_RECOMPILE = 2;
    /************************************************************
     * SUPPORTED CONSTANTS                                      *
     ************************************************************/
    const P2U_RECODE = 1;
    const P2U_PROPERTIES = 2;
    const P2U_MODIFIER = 3;
    const P2U_ALL = 3;
    /************************************************************
     * PROPERTIES                                               *
     ************************************************************/
    /**
     * @var FileSystem|null;
     */
    private $_class_filesystem;
    /**
     * internal encoding
     * @var string|null
     */
    protected $internal_encoding;
    /**
     * language alphabet lowercase
     * @var string|null
     */
    protected $alphabet;
    /**
     * language alphabet uppercase
     * @var string|null
     */
    protected $alphabet_uc;
    /**
     * translation table for the language
     * @var array|null
     */
    protected $translation;
    /**
     * compiled dictionary
     * @var array|null
     */
    protected $dictionary;
    /**
     * left hyphenation limit for the language
     * @var int|null
     */
    protected $min_left_limit;
    /**
     * right hyphenation limit for the language
     * @var int|null
     */
    protected $min_right_limit;
    /**
     * current left hyphenation limit
     * @var string (­||&shy;)
     */
    protected $soft_hyphen = '­';
    /**
     * input/output encoding
     * @var string
     */
    protected $io_encoding = '';
    /**
     * proceed uppercase
     * @var bool
     */
    public $proceed_uppercase = false;
    /**
     * current left hyphenation limit
     * @var int|null
     */
    public $left_limit;
    /**
     * current right hyphenation limit
     * @var int|null
     */
    public $right_limit;
    /**
     * minimum word length allowing hyphenation
     * @var int|null
     */
    public $length_limit;
    /**
     * current right hyphenation limit
     * @var int|null
     */
    public $right_limit_last;
    /**
     * current left hyphenation limit
     * @var int|null
     */
    public $left_limit_uc;
    /************************************************************
     * MAGIC METHODS                                            *
     ************************************************************/
    /**
     * Hyphenation constructor
     * @param string $lang
     * @param int $recompile
     * @throws \Exception
     */
    public function __construct($lang, $recompile = self::AUTO_RECOMPILE)
    {
        $configuration_file = $this->get_config_file($lang);
        if (!is_file($configuration_file)) {
            return false;
        }
        $conf = $this->parse_config($configuration_file);
        if (!$conf) {
            return false;
        }
        $path = dirname($configuration_file);
        if (isset($conf['compiled'][0])) {
            $conf['compiled'][0] = $path . '/' . $conf['compiled'][0];
        }
        if (!is_file($conf['compiled'][0])) {
            $recompile = self::ALWAYS_RECOMPILE;
        }
        if (isset($conf['rules'])) {
            foreach ($conf['rules'] as $key => $val) {
                $conf['rules'][$key] = $path . '/' . $val;
            }
        } else {
            return false;
        }
        // define the necessity to remake dictionary
        if ($recompile == self::AUTO_RECOMPILE) {
            $date_out = $this->get_array_value($this->get_class_filesystem()->get_stat($conf['compiled'][0]), 'mtime');
            $date_in = $this->get_array_value($this->get_class_filesystem()->get_stat($configuration_file), 'mtime');
            foreach ($conf['rules'] as $val) {
                $date_in = max($date_in, $this->get_array_value($this->get_class_filesystem()->get_stat($val), 'mtime'));
            }
            if ($date_in > $date_out) {
                $recompile = self::ALWAYS_RECOMPILE;
            }
        }
        // recompile the dictionary in case of version mismatch
        if ($recompile != self::ALWAYS_RECOMPILE) {
            $ret = unserialize($this->get_content($conf['compiled'][0]));
            if (!isset($ret['ver']) || $ret['ver'] !== self::VERSION) {
                $recompile = self::ALWAYS_RECOMPILE;
            }
        }
        // recompile and save the dictionary
        if ($recompile == self::ALWAYS_RECOMPILE) {
            $ret = [];
            // parse alphabet
            $ret['alphabet'] = preg_replace('/\((.+)\>(.+)\)/U', '$1', $conf['alphabet'][0]);
            $ret['alphabetUC'] = $conf['alphabetUC'][0];
            // make translation table
            if (preg_match_all('/\((.+)\>(.+)\)/U', $conf['alphabet'][0], $matches, PREG_PATTERN_ORDER)) {
                foreach ($matches[1] as $key => $val) {
                    $ret['trans'][$val] = $matches[2][$key];
                }
            } else $ret['trans'] = [];
            $ret['ll'] = $conf['left_limit'][0];
            $ret['rl'] = $conf['right_limit'][0];
            $ret['enc'] = $conf['internal_encoding'][0];
            $ret['ver'] = self::VERSION;
            foreach ($conf['rules'] as $fnm) {
                if (is_file($fnm)) {
                    $in_file = explode("\n", $this->clean_config($this->get_content($fnm)));
                    // first string of the rules file is the encoding of this file
                    $encoding = $in_file[0];
                    unset($in_file[0]);
                    // create rules array: keys -- letters combinations; values -- digital masks
                    foreach ($in_file as $str) {
                        // translate rules to internal encoding
                        if (strcasecmp($encoding, $ret['enc']) != 0) {
                            $str = @iconv($encoding, $ret['enc'], $str);
                        }
                        // patterns not containing digits and dots are treated as dictionary words
                        // converting ones to pattern
                        if (!preg_match('/[\d\.]/', $str)) {
                            $str = str_replace('-', '9', $str);
                            $str = preg_replace('/(?<=\D)(?=\D)/', '8', $str);
                            $str = '.' . $str . '.';
                        }
                        // insert zero between the letters
                        $str = preg_replace('/(?<=\D)(?=\D)/', '0', $str);
                        // insert zero on beginning and on the end
                        if (preg_match('/^\D/', $str)) $str = '0' . $str;
                        if (preg_match('/\D$/', $str)) $str .= '0';
                        // make array
                        $ind = preg_replace('/[\d\n\s]/', '', $str);
                        $vl = preg_replace('/\D/', '', $str);
                        if ($ind != '' && $vl != '') {
                            $ret['dict'][$ind] = $vl;
                            // optimize: if there is, for example, "abcde" pattern
                            // then we need "abcd", "abc", "ab" and "a" patterns
                            // to be presented
                            $sb = $ind;
                            do {
                                $sb = mb_substr($sb, 0, mb_strlen($sb) - 1);
                                if (!isset($ret['dict'][$sb])) {
                                    $ret['dict'][$sb] = 0;
                                } else {
                                    break;
                                }
                            } while (mb_strlen($sb) > 1);
                        }
                    }
                }
            }
            if (isset($conf['compiled'][0])) {
                $conf_filename = $this->normalize_filename($conf['compiled'][0]);
                $this->get_class_filesystem()->create_directory(dirname($conf_filename));
                $this->get_class_filesystem()->put_content($conf_filename, serialize($ret));
            }
        }
        $this->internal_encoding = isset($ret, $ret['enc']) ? $ret['enc'] : null;
        $this->alphabet = isset($ret, $ret['alphabet']) ? $ret['alphabet'] : null;
        $this->translation = isset($ret, $ret['trans']) ? $ret['trans'] : null;
        $this->dictionary = isset($ret, $ret['dict']) ? $ret['dict'] : null;
        $this->min_left_limit = isset($ret, $ret['ll']) ? $ret['ll'] : null;
        $this->min_right_limit = isset($ret, $ret['rl']) ? $ret['rl'] : null;
        $this->check_limits();
        return true;
    }
    /************************************************************
     * PUBLIC METHODS                                           *
     ************************************************************/
    /**
     * Hyphenation
     * @param string $instr
     * @param string $encoding input/output encoding
     * @param string $shy hyphen symbol or string
     * @param bool $preserveTags if set to TRUE (default), then do not process words inside <>
     * @return mixed
     */
    public function hyphenate($instr, $encoding = '', $shy = '', $preserveTags = true)
    {
        if ($shy) {
            $this->soft_hyphen = $shy;
        }
        $alphabet = $this->alphabet . $this->alphabet_uc;
        if (!$encoding || strcasecmp($this->internal_encoding, $encoding) == 0) {
            $uni = '';
        } else {
            $alphabet = @iconv($this->internal_encoding, $encoding, $alphabet);
            $uni = (preg_match('/^utf\-?8$/i', $encoding)) ? 'u' : '';
        }
        $this->check_limits();
        $pattern = $preserveTags ? '/(?<![' . $alphabet . '\x5C])([' . $alphabet . ']{' . $this->length_limit . ',})(?!([^<]+)?>)/' : '/(?<![' . $alphabet . '\x5C])([' . $alphabet . ']{' . $this->length_limit . ',})(' . (($uni) ? '\P{L}' : '[^' . $alphabet . '\w]') . '*[\n\r])?/';
        if (!preg_match_all($pattern . $uni, $instr, $matches, PREG_OFFSET_CAPTURE)) {
            return $instr;
        }
        // last word in the stream should be treated as the last word of paragraph
        $matches[2][sizeof($matches[1]) - 1][0] = '1';
        $offset = 0;
        $this->io_encoding = $encoding;
        $start_instr = $instr;
        foreach ($matches[1] as $i => $match) {
            $word = $match[0];
            $pos = mb_strlen(substr($start_instr, 0, $match[1] + 1), $this->internal_encoding) - 1;
            $hyphenation_word = $this->hyphenate_word($word, (isset($matches[2][$i][0]) && $matches[2][$i][0] !== ''));
            $instr = $this->mb_substr_replace($instr, $hyphenation_word, $pos + $offset, mb_strlen($word), $this->io_encoding);
            $offset += mb_strlen($hyphenation_word, $this->io_encoding) - mb_strlen($word, $this->io_encoding);
        }
        return $instr;
    }

    /**
     * Set limits
     * @param int $left_limit
     * @param int $right_limit
     * @param int $length_limit
     * @param int $right_limit_last
     * @param int $left_limit_uc
     */
    public function set_limits($left_limit = 0, $right_limit = 0, $length_limit = 0, $right_limit_last = 0, $left_limit_uc = 0)
    {
        $this->left_limit = $left_limit;
        $this->right_limit = $right_limit;
        $this->length_limit = $length_limit;
        $this->right_limit_last = $right_limit_last;
        $this->left_limit_uc = $left_limit_uc;
        $this->check_limits();
    }
    /************************************************************
     * SUPPORTED METHODS                                        *
     ************************************************************/
    /**
     * Check limits
     */
    protected function check_limits()
    {
        $this->left_limit = max($this->left_limit, $this->min_left_limit);
        $this->right_limit = max($this->right_limit, $this->min_right_limit);
        $this->length_limit = max($this->length_limit, $this->left_limit + $this->right_limit);
        $this->right_limit_last = max($this->right_limit, $this->right_limit_last);
        $this->left_limit_uc = max($this->left_limit, $this->left_limit_uc);
    }

    /**
     * Get config file
     * @param string $lang
     * @return string
     */
    protected function get_config_file($lang = 'ru_RU')
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . $lang . '.conf';
    }

    /**
     * Hyphenate the word, you don't need to call it directly.
     * @param string $instr
     * @param bool $last_word
     * @return string
     */
    protected function hyphenate_word($instr, $last_word = false)
    {
        $to_transcode = false;
        // convert the word to the internal encoding
        $word = ($to_transcode = ($this->io_encoding && strcasecmp($this->internal_encoding, $this->io_encoding) != 0)) ? @iconv($this->io_encoding, $this->internal_encoding, $instr) : $instr;
        // convert soft_hyphen to internal encoding
        $hyphen = ($to_transcode) ? @iconv($this->io_encoding, $this->internal_encoding, $this->soft_hyphen) : $this->soft_hyphen;
        // \x5C character (backslash) indicates to not process this world at all
        if (false !== mb_strpos($word, "\x5C", 0, $this->internal_encoding)) {
            return $instr;
        }
        // convert the first letter to low case
        $word_lower = $word;
        $st_pos = mb_strpos($this->alphabet_uc, mb_substr($word, 0, 1, $this->internal_encoding), 0, $this->internal_encoding);
        if ($st_pos !== false) {
            $ll = $this->left_limit_uc;
            $word_lower = mb_substr($this->alphabet, $st_pos, 1, $this->internal_encoding) . mb_substr($word_lower, 1, null, $this->internal_encoding);
        } else {
            $ll = $this->left_limit;
        }
        $rl = ($last_word) ? $this->right_limit_last : $this->right_limit;
        // check all letters but the first for upper case
        for ($i = 1, $len = mb_strlen($word_lower, $this->internal_encoding); $i < $len; $i++) {
            $st_pos = mb_strpos($this->alphabet_uc, mb_substr($word, $i, 1, $this->internal_encoding), 0, $this->internal_encoding);
            if ($st_pos !== false) {
                if ($this->proceed_uppercase) {
                    $word_lower = ($i > 0 ? mb_substr($word_lower, 0, $i, $this->internal_encoding) : '') . mb_substr($this->alphabet, $st_pos, 1, $this->internal_encoding) . mb_substr($word_lower, $i + 1, null, $this->internal_encoding);
                } else {
                    return $instr;
                }
            }
        }
        $word_lower = '.' . $word_lower . '.';
        $word = '.' . $word . '.';
        $len = mb_strlen($word, $this->internal_encoding);
        // translate letters
        foreach ($this->translation as $key => $val) {
            $word_lower = str_replace($key, $val, $word_lower);
        }
        $word_mask = $this->mb_str_split(str_repeat('0', $len + 1));
        // step by step cycle
        for ($i = 0; $i < $len - 1; $i++) {
            // Increasing fragment's length cycle.
            // The first symbol of the word always is dot,
            // so we don't need to check 1-length fragment at the first step
            for ($k = ($i == 0) ? 2 : 1; $k <= $len - $i; $k++) {
                $ind = mb_substr($word_lower, $i, $k, $this->internal_encoding);
                // fallback
                if (!isset($this->dictionary[$ind])) {
                    break;
                }
                $val = $this->dictionary[$ind];
                if ($val !== 0) {
                    for ($j = 0; $j <= $k; $j++) {
                        $word_mask[$i + $j] = max($word_mask[$i + $j], mb_substr($val, $j, 1, $this->internal_encoding));
                    }
                }
            }
        }
        $ret = '';
        $syllable = false;
        foreach ($this->mb_str_split($word) as $key => $val) {
            if ($val != '.') {
                $ret .= $val;
                if ($syllable && $key > $ll - 1 && $key < $len - $rl - 1 && $word_mask[$key + 1] % 2) {
                    $ret .= $hyphen;
                    $syllable = false;
                } else {
                    $syllable = true;
                }
            }
        }
        // convert the word back to native encoding
        if ($to_transcode) {
            $ret = @iconv($this->internal_encoding, $this->io_encoding, $ret);
        }
        return $ret;
    }

    /**
     * Service function
     * @param string $instr
     * @param bool $is_unicode
     * @return string
     */
    protected function screen_special($instr, $is_unicode = false)
    {
        $patterns = [];
        $replaces = [];
        $patterns[] = '/\n/';
        $replaces[] = '&SCREENEDLFEED&';
        $patterns[] = '/\s/';
        $replaces[] = '&SCREENEDSPACE&';
        $patterns[] = '/\'/';
        $replaces[] = '&SCREENSNQUOTE&';
        $patterns[] = '/\x5C?\"/';
        $replaces[] = '&SCREENDBQUOTE&';
        $patterns[] = '/\/\//';
        $replaces[] = '&SCREENDBSLASH&';
        $patterns[] = '/=/';
        $replaces[] = '&SCREENEDEQUAL&';
        if ($is_unicode) {
            $patterns = $this->pattern2unicode($patterns);
        }
        return preg_replace($patterns, $replaces, $instr);
    }

    /**
     * Remove comments and empty lines
     * @param string $instr
     * @param bool $is_unicode
     * @return string
     */
    private function clean_config($instr, $is_unicode = false)
    {
        $patterns = [];
        $replaces = [];
        $patterns[] = '/\/\/.*$/m';
        $replaces[] = '';
        $patterns[] = '/^\s*/m';
        $replaces[] = '';
        $patterns[] = '/\s*$/m';
        $replaces[] = '';
        $patterns[] = '/(?<=\n)\n+/';
        $replaces[] = '';
        $patterns[] = '/\n$/';
        $replaces[] = '';
        $patterns[] = '/^\n/';
        $replaces[] = '';
        if ($is_unicode) {
            $patterns = $this->pattern2unicode($patterns);
        }
        return preg_replace($patterns, $replaces, $this->unix_line_feeds($instr, $is_unicode));
    }

    /**
     * Returns value of array by key
     * @param array $arr
     * @param string $k
     * @return bool
     */
    private function get_array_value($arr, $k)
    {
        return (is_array($arr) && isset($arr[$k])) ? $arr[$k] : false;
    }

    /**
     * @return FileSystem
     */
    private function get_class_filesystem()
    {
        if (!$this->_class_filesystem) {
            $this->_class_filesystem = new FileSystem();
        }
        return $this->_class_filesystem;
    }

    /**
     * Get file content
     * @param $filename
     * @return string
     */
    private function get_content($filename)
    {
        $class = $this->get_class_filesystem();
        return $class->remove_bom($class->get_content($filename));
    }

    /**
     * Normalize filename
     * @param string $filename
     * @return string
     */
    private function normalize_filename($filename)
    {
        return $this->get_class_filesystem()->normalize($filename);
    }

    /**
     * Parse config file
     * @param $conf_file
     * @param bool $is_unicode
     * @return array|bool
     */
    private function parse_config($conf_file, $is_unicode = false)
    {
        if (!is_file($conf_file) || !is_readable($conf_file)) {
            return false;
        }
        $in_file = $this->get_content($conf_file);
        if (!$in_file) {
            return false;
        } else {
            return $this->parse_config_str($in_file, $is_unicode);
        }
    }

    /**
     * Parse config string
     * @param $str
     * @param bool $is_unicode
     * @return array
     */
    private function parse_config_str($str, $is_unicode = false)
    {
        $patterns = [];
        $replaces = [];
        $patterns[] = '/&SCREENEDSPACE&/';
        $replaces[] = ' ';
        $patterns[] = '/&SCREENEDLFEED&/';
        $replaces[] = "\n";
        $patterns[] = '/&SCREENSNQUOTE&/';
        $replaces[] = '\'';
        $patterns[] = '/&SCREENDBQUOTE&/';
        $replaces[] = '"';
        $patterns[] = '/&SCREENDBSLASH&/';
        $replaces[] = '//';
        $patterns[] = '/&SCREENEDEQUAL&/';
        $replaces[] = '=';
        if ($is_unicode) {
            $patterns = $this->pattern2unicode($patterns);
        }
        $ret = [];
        $str = $this->unix_line_feeds($str, $is_unicode);
        $tmp_pattern = '/(?<=\=)\s*\'\'/';
        if ($is_unicode) {
            $tmp_pattern = $this->pattern2unicode($tmp_pattern);
        }
        $str = preg_replace($tmp_pattern, '$1', $str);
        $tmp_pattern = '/(?<!\x5C)\'(.*[^\x5C])\'/Us';
        if ($is_unicode) {
            $tmp_pattern = $this->pattern2unicode($tmp_pattern);
            $str = preg_replace_callback($tmp_pattern,
                create_function('$in', 'return $this->screen_special($in[1], true);'), $str);
        } else {
            $str = preg_replace_callback($tmp_pattern,
                create_function('$in', 'return $this->screen_special($in[1], false);'), $str);
        }
        $str = $this->clean_config($str, $is_unicode);
        $strings = explode("\n", $str);
        foreach ($strings as $val) {
            $pair = explode('=', $val);
            if (isset($pair[0])) {
                $ret[trim($pair[0])][] = (isset($pair[1])) ? preg_replace($patterns, $replaces, trim($pair[1])) : true;
            }
        }
        return $ret;
    }

    /**
     * Convert pattern to unicode
     * @param $pattern
     * @param string $from_enc
     * @param int $flags
     * @return array|string
     */
    private function pattern2unicode($pattern, $from_enc = 'ISO-8859-1', $flags = self::P2U_ALL)
    {
        if (is_array($pattern)) {
            // pattern is array: recursive call
            $ret = [];
            foreach ($pattern as $key => $val) {
                $ret[$key] = $this->pattern2unicode($val, $from_enc, $flags);
            }
        } elseif (is_string($pattern)) {
            // pattern is string: process it
            // recode pattern
            $ret = ($flags & self::P2U_RECODE) ? @iconv($from_enc, 'UTF-8', $pattern) : $pattern;
            // convert types to properties
            if ($flags & self::P2U_PROPERTIES) {
                $patterns = [];
                $replaces = [];
                $patterns[] = '/(?<!(?<!(?<!\x5C)\x5C)\x5C)\x5Cd/';
                $replaces[] = '\p{Nd}';
                $patterns[] = '/(?<!(?<!(?<!\x5C)\x5C)\x5C)\x5CD/';
                $replaces[] = '\P{Nd}';
                $patterns[] = '/(?<!(?<!(?<!\x5C)\x5C)\x5C)\x5Cw/';
                $replaces[] = '\p{L}';
                $patterns[] = '/(?<!(?<!(?<!\x5C)\x5C)\x5C)\x5CW/';
                $replaces[] = '\P{L}';
                $patterns[] = '/(?<!(?<!(?<!\x5C)\x5C)\x5C)\x5Cs/';
                $replaces[] = '\p{Zs}';
                $patterns[] = '/(?<!(?<!(?<!\x5C)\x5C)\x5C)\x5CS/';
                $replaces[] = '\P{Zs}';
                $ret = preg_replace($patterns, $replaces, $ret);
            }
            // add pattern modifier
            $ret .= ($flags & self::P2U_MODIFIER) ? 'u' : '';
            // pattern is not string nor array: return as is
        } else {
            $ret = $pattern;
        }
        return $ret;
    }

    /**
     * Converts dos line feeds (\r\n) or mac ones (\r) to unix format (\n)
     * @param string $str
     * @param bool $is_unicode
     * @return string
     */
    private function unix_line_feeds($str, $is_unicode = false)
    {
        return preg_replace('/\r\n?/' . (($is_unicode) ? 'u' : ''), "\n", $str);
    }

    /**
     * Convert a string to an array
     * @param string $str
     * @param int $l
     * @param string|null $encoding
     * @return array|bool
     */
    private function mb_str_split($str, $l = 1, $encoding = null)
    {
        if (is_null($encoding)) {
            $encoding = mb_internal_encoding();
        }
        if ($l > 0) {
            $ret = [];
            $len = mb_strlen($str, $encoding);
            for ($i = 0; $i < $len; $i += $l) {
                $ret[] = mb_substr($str, $i, $l, $encoding);
            }
            return $ret;
        } else {
            return false;
        }
    }

    /**
     * @param string $string
     * @param string $replacement
     * @param int $start
     * @param int|null $length
     * @param string|null $encoding
     * @return mixed|string
     */
    private function mb_substr_replace($string, $replacement, $start, $length = null, $encoding = null)
    {
        if (is_null($encoding)) {
            $encoding = mb_internal_encoding();
        }
        $string_length = is_null($encoding) ? mb_strlen($string) : mb_strlen($string, $encoding);
        if ($start < 0) {
            $start = max(0, $string_length + $start);
        } else if ($start > $string_length) {
            $start = $string_length;
        }
        if ($length < 0) {
            $length = max(0, $string_length - $start + $length);
        } else if (is_null($length) || ($length > $string_length)) {
            $length = $string_length;
        }
        if (($start + $length) > $string_length) {
            $length = $string_length - $start;
        }
        return mb_substr($string, 0, $start, $encoding) . $replacement . mb_substr($string, $start + $length, $string_length - $start - $length, $encoding);
    }
}
