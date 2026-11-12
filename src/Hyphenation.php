<?php

namespace Hyphenation;

/**
 * Liang–Knuth hyphenation for Russian, English and German.
 *
 * Modified 11 November 2026 version of phpHypher 0.1.2.
 * Copyright (C) 2008-2010 Sergey Kurakin <sergeykurakin@gmail.com>
 * Copyright (C) 2018-2026 Sergei Gruzdov <cardee@mifesta.com>
 *
 * This library is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pattern files in src/patterns keep the notices contained in those files.
 */
class Hyphenation
{
    const VERSION = '1.0.4';
    const AUTO_RECOMPILE = 0;
    const NEVER_RECOMPILE = 1;
    const ALWAYS_RECOMPILE = 2;

    const P2U_RECODE = 1;
    const P2U_PROPERTIES = 2;
    const P2U_MODIFIER = 3;
    const P2U_ALL = 3;

    /**
     * internal encoding
     * @var string
     */
    protected $internal_encoding = 'UTF-8';
    /**
     * language alphabet lowercase
     * @var string
     */
    protected $alphabet = '';
    /**
     * language alphabet uppercase
     * @var string
     */
    protected $alphabet_uc = '';
    /**
     * translation table for the language
     * @var array
     */
    protected $translation = array();
    /**
     * compiled dictionary
     * @var array
     */
    protected $dictionary = array();
    /**
     * left hyphenation limit for the language
     * @var int
     */
    protected $min_left_limit = 0;
    /**
     * right hyphenation limit for the language
     * @var int
     */
    protected $min_right_limit = 0;
    /**
     * soft hyphen, stored as UTF-8
     * @var string
     */
    protected $soft_hyphen = "\u{00AD}";
    /**
     * input/output encoding
     * @var string
     */
    protected $io_encoding = '';
    /**
     * hyphenate words that contain capitals after the first letter
     * @var bool
     */
    public $proceed_uppercase = false;
    /**
     * current left hyphenation limit
     * @var int
     */
    public $left_limit = 0;
    /**
     * current right hyphenation limit
     * @var int
     */
    public $right_limit = 0;
    /**
     * minimum word length allowing hyphenation
     * @var int
     */
    public $length_limit = 0;
    /**
     * right limit for the last word
     * @var int
     */
    public $right_limit_last = 0;
    /**
     * left limit for a word that starts with a capital
     * @var int
     */
    public $left_limit_uc = 0;

    /**
     * @param string $lang language id: ru_RU, en_US, en_GB, de-1996, de_DE
     * @param int $recompile AUTO_RECOMPILE, NEVER_RECOMPILE or ALWAYS_RECOMPILE
     */
    public function __construct($lang, $recompile = self::AUTO_RECOMPILE)
    {
        if (!is_string($lang) || preg_match('/^[A-Za-z0-9_-]+$/', $lang) !== 1) {
            throw new \InvalidArgumentException('Unknown hyphenation language.');
        }

        $configFile = $this->config_file($lang);
        if (!is_file($configFile)) {
            throw new \InvalidArgumentException('Hyphenation language is not supported: ' . $lang);
        }

        $conf = $this->parse_config($configFile);
        if (!is_array($conf)) {
            throw new \InvalidArgumentException('Hyphenation config is invalid: ' . $lang);
        }

        foreach (array('alphabet', 'alphabetUC', 'left_limit', 'right_limit', 'internal_encoding') as $key) {
            if (!isset($conf[$key][0]) || $conf[$key][0] === '') {
                throw new \InvalidArgumentException('Hyphenation config is missing ' . $key . ': ' . $lang);
            }
        }
        if (empty($conf['rules']) || !is_array($conf['rules'])) {
            throw new \InvalidArgumentException('Hyphenation config has no rules: ' . $lang);
        }

        $configDir = dirname($configFile);
        $ruleFiles = array();
        foreach ($conf['rules'] as $relative) {
            $full = $this->resolve_existing($configDir, $relative);
            if ($full === null) {
                throw new \InvalidArgumentException('Hyphenation rules file was not found: ' . $relative);
            }
            $ruleFiles[] = $full;
        }

        $compiled = null;
        if (!empty($conf['compiled'][0])) {
            $compiled = $this->resolve_target($configDir, $conf['compiled'][0]);
        }

        $recompile = (int) $recompile;
        $fingerprint = $this->fingerprint($configFile, $ruleFiles);
        $ret = null;
        if ($recompile !== self::ALWAYS_RECOMPILE) {
            $ret = $this->load_cache($compiled, $lang, $fingerprint, $recompile === self::NEVER_RECOMPILE);
        }
        if ($ret === null) {
            $ret = $this->compile($conf, $ruleFiles, $fingerprint);
            $this->store_cache($compiled, $lang, $ret);
        }

        $this->apply($ret);
    }

    /**
     * Insert soft hyphens.
     *
     * @param string $instr
     * @param string $encoding encoding of $instr; empty means the language internal encoding
     * @param string $shy UTF-8 hyphen marker; empty keeps the current one (U+00AD by default)
     * @param bool $preserveTags skip words that sit inside <...>
     * @return string
     */
    public function hyphenate($instr, $encoding = '', $shy = '', $preserveTags = true)
    {
        $instr = (string) $instr;
        if ($instr === '' || $this->dictionary === array() || $this->alphabet === '') {
            return $instr;
        }
        if ($shy !== null && $shy !== '') {
            $this->soft_hyphen = (string) $shy;
        }

        $encoding = $this->normalize_encoding(
            ($encoding === null || $encoding === '') ? $this->internal_encoding : $encoding
        );
        $this->io_encoding = $encoding;
        $this->check_limits();

        $alphabet = $this->alphabet . $this->alphabet_uc;
        if (strcasecmp($this->internal_encoding, $encoding) !== 0) {
            $converted = @iconv($this->internal_encoding, $encoding, $alphabet);
            if (!is_string($converted) || $converted === '') {
                return $instr;
            }
            $alphabet = $converted;
        }
        $alphabet = $this->escape_class($alphabet);
        $utf8 = ($encoding === 'UTF-8');
        $minLength = (int) $this->length_limit;

        if ($preserveTags) {
            $pattern = '/(?<![' . $alphabet . '\x5C])([' . $alphabet . ']{' . $minLength . ',})(?!([^<]+)?>)/';
        } else {
            $gap = $utf8 ? '\P{L}' : '[^' . $alphabet . '\w]';
            $pattern = '/(?<![' . $alphabet . '\x5C])([' . $alphabet . ']{' . $minLength . ',})(' . $gap . '*[\n\r])?/';
        }
        if ($utf8) {
            $pattern .= 'u';
        }

        $original = $instr;
        $matched = @preg_match_all($pattern, $original, $matches, PREG_OFFSET_CAPTURE);
        if (!$matched) {
            return $instr;
        }

        $last = count($matches[1]) - 1;
        $matches[2][$last][0] = '1';
        $offset = 0;
        foreach ($matches[1] as $i => $match) {
            $word = $match[0];
            $pos = mb_strlen(substr($original, 0, $match[1]), $encoding) + $offset;
            $hyphenated = $this->hyphenate_word(
                $word,
                isset($matches[2][$i][0]) && $matches[2][$i][0] !== ''
            );
            $wordLength = mb_strlen($word, $encoding);
            $instr = $this->mb_substr_replace($instr, $hyphenated, $pos, $wordLength, $encoding);
            $offset += mb_strlen($hyphenated, $encoding) - $wordLength;
        }
        return $instr;
    }

    /**
     * Raise hyphenation limits. Values below the language minimum are ignored.
     *
     * @param int $left_limit
     * @param int $right_limit
     * @param int $length_limit
     * @param int $right_limit_last
     * @param int $left_limit_uc
     */
    public function set_limits($left_limit = 0, $right_limit = 0, $length_limit = 0, $right_limit_last = 0, $left_limit_uc = 0)
    {
        $this->left_limit = (int) $left_limit;
        $this->right_limit = (int) $right_limit;
        $this->length_limit = (int) $length_limit;
        $this->right_limit_last = (int) $right_limit_last;
        $this->left_limit_uc = (int) $left_limit_uc;
        $this->check_limits();
    }

    /**
     * @param string $instr
     * @param bool $last_word
     * @return string
     */
    protected function hyphenate_word($instr, $last_word = false)
    {
        $instr = (string) $instr;
        if ($instr === '') {
            return $instr;
        }

        $enc = $this->internal_encoding;
        $to_transcode = strcasecmp($enc, $this->io_encoding) !== 0;
        if ($to_transcode) {
            $word = @iconv($this->io_encoding, $enc, $instr);
            if (!is_string($word) || $word === '') {
                return $instr;
            }
        } else {
            $word = $instr;
        }
        $hyphen = $this->marker($enc);
        if (mb_strpos($word, "\\", 0, $enc) !== false) {
            return $instr;
        }

        $word_lower = $word;
        $first = mb_substr($word, 0, 1, $enc);
        $st_pos = mb_strpos($this->alphabet_uc, $first, 0, $enc);
        if ($st_pos !== false) {
            $ll = $this->left_limit_uc;
            $word_lower = mb_substr($this->alphabet, $st_pos, 1, $enc) . mb_substr($word_lower, 1, null, $enc);
        } else {
            $ll = $this->left_limit;
        }
        $rl = $last_word ? $this->right_limit_last : $this->right_limit;

        $len = mb_strlen($word_lower, $enc);
        for ($i = 1; $i < $len; $i++) {
            $st_pos = mb_strpos($this->alphabet_uc, mb_substr($word, $i, 1, $enc), 0, $enc);
            if ($st_pos === false) {
                continue;
            }
            if (!$this->proceed_uppercase) {
                return $instr;
            }
            $word_lower = mb_substr($word_lower, 0, $i, $enc)
                . mb_substr($this->alphabet, $st_pos, 1, $enc)
                . mb_substr($word_lower, $i + 1, null, $enc);
        }

        $word_lower = '.' . $word_lower . '.';
        $word = '.' . $word . '.';
        $len = mb_strlen($word, $enc);
        foreach ($this->translation as $key => $val) {
            $word_lower = str_replace($key, $val, $word_lower);
        }

        $word_mask = $this->mb_str_split(str_repeat('0', $len + 1), 1, $enc);
        for ($i = 0; $i < $len - 1; $i++) {
            $maxFragment = $len - $i;
            for ($k = ($i === 0) ? 2 : 1; $k <= $maxFragment; $k++) {
                $ind = mb_substr($word_lower, $i, $k, $enc);
                if (!isset($this->dictionary[$ind])) {
                    break;
                }
                $val = $this->dictionary[$ind];
                if ($val === 0) {
                    continue;
                }
                $val = (string) $val;
                for ($j = 0; $j <= $k; $j++) {
                    $digit = mb_substr($val, $j, 1, $enc);
                    if ($digit === '' || $digit === false || !isset($word_mask[$i + $j])) {
                        break;
                    }
                    $word_mask[$i + $j] = (string) max($word_mask[$i + $j], $digit);
                }
            }
        }

        $ret = '';
        $syllable = false;
        foreach ($this->mb_str_split($word, 1, $enc) as $key => $val) {
            if ($val === '.') {
                continue;
            }
            $ret .= $val;
            $mark = isset($word_mask[$key + 1]) ? $word_mask[$key + 1] : '0';
            if ($syllable && $key > $ll - 1 && $key < $len - $rl - 1 && ((int) $mark) % 2 === 1) {
                $ret .= $hyphen;
                $syllable = false;
            } else {
                $syllable = true;
            }
        }

        if ($to_transcode) {
            $back = @iconv($enc, $this->io_encoding, $ret);
            if (!is_string($back) || $back === '') {
                return $instr;
            }
            return $back;
        }
        return $ret;
    }

    /**
     * @return void
     */
    protected function check_limits()
    {
        $this->left_limit = max((int) $this->left_limit, (int) $this->min_left_limit);
        $this->right_limit = max((int) $this->right_limit, (int) $this->min_right_limit);
        $this->length_limit = max((int) $this->length_limit, $this->left_limit + $this->right_limit);
        $this->right_limit_last = max($this->right_limit, (int) $this->right_limit_last);
        $this->left_limit_uc = max($this->left_limit, (int) $this->left_limit_uc);
    }

    /**
     * @param string $lang
     * @return string
     */
    protected function config_file($lang)
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . $lang . '.conf';
    }

    /**
     * @param array $conf
     * @param array $ruleFiles
     * @param string $fingerprint
     * @return array
     */
    private function compile(array $conf, array $ruleFiles, $fingerprint)
    {
        $rawAlphabet = $conf['alphabet'][0];
        $ret = array(
            'alphabet' => preg_replace('/\((.+)\>(.+)\)/uU', '$1', $rawAlphabet),
            'alphabetUC' => $conf['alphabetUC'][0],
            'trans' => array(),
            'dict' => array(),
            'll' => (int) $conf['left_limit'][0],
            'rl' => (int) $conf['right_limit'][0],
            'enc' => $this->normalize_encoding($conf['internal_encoding'][0]),
            'ver' => self::VERSION,
            'fingerprint' => $fingerprint,
        );
        if (preg_match_all('/\((.+)\>(.+)\)/uU', $rawAlphabet, $matches, PREG_PATTERN_ORDER)) {
            foreach ($matches[1] as $key => $val) {
                $ret['trans'][$val] = $matches[2][$key];
            }
        }

        foreach ($ruleFiles as $filename) {
            $text = $this->read_file($filename);
            if (!is_string($text) || $text === '') {
                throw new \InvalidArgumentException('Hyphenation rules file is empty: ' . $filename);
            }
            $lines = explode("\n", $this->clean_config($text));
            if (!isset($lines[0]) || $lines[0] === '') {
                throw new \InvalidArgumentException('Hyphenation rules file has no encoding: ' . $filename);
            }
            $encoding = $lines[0];
            unset($lines[0]);
            foreach ($lines as $str) {
                if ($str === '') {
                    continue;
                }
                if (strcasecmp($encoding, $ret['enc']) !== 0) {
                    $converted = @iconv($encoding, $ret['enc'], $str);
                    if (!is_string($converted) || $converted === '') {
                        continue;
                    }
                    $str = $converted;
                }
                $pair = $this->pattern_to_pair($str);
                if ($pair === null) {
                    continue;
                }
                $ret['dict'][$pair[0]] = $pair[1];
                $this->add_prefixes($ret['dict'], $pair[0], $ret['enc']);
            }
        }

        if ($ret['dict'] === array()) {
            throw new \InvalidArgumentException('Hyphenation rules produced an empty dictionary.');
        }
        return $ret;
    }

    /**
     * Turn one pattern line into a dictionary key and a digit mask.
     * Zeros are inserted between characters, so the mask stays aligned for UTF-8.
     *
     * @param string $str
     * @return array|null
     */
    private function pattern_to_pair($str)
    {
        if (preg_match('/[\d.]/u', $str) !== 1) {
            $str = str_replace('-', '9', $str);
            $replaced = preg_replace('/(?<=\D)(?=\D)/u', '8', $str);
            if (!is_string($replaced)) {
                return null;
            }
            $str = '.' . $replaced . '.';
        }
        $replaced = preg_replace('/(?<=\D)(?=\D)/u', '0', $str);
        if (!is_string($replaced)) {
            return null;
        }
        $str = $replaced;
        if (preg_match('/^\D/u', $str) === 1) {
            $str = '0' . $str;
        }
        if (preg_match('/\D$/u', $str) === 1) {
            $str .= '0';
        }
        $ind = preg_replace('/[\d\n\s]/u', '', $str);
        $vl = preg_replace('/\D/u', '', $str);
        if (!is_string($ind) || !is_string($vl) || $ind === '' || $vl === '') {
            return null;
        }
        return array($ind, $vl);
    }

    /**
     * Shorter keys must exist so the matcher can stop on a missing prefix.
     *
     * @param array $dict
     * @param string $ind
     * @param string $enc
     */
    private function add_prefixes(array &$dict, $ind, $enc)
    {
        $length = mb_strlen($ind, $enc);
        while ($length > 1) {
            $length--;
            $ind = mb_substr($ind, 0, $length, $enc);
            if (isset($dict[$ind])) {
                break;
            }
            $dict[$ind] = 0;
        }
    }

    /**
     * @param array $ret
     */
    private function apply(array $ret)
    {
        $enc = (string) $ret['enc'];
        $alphabet = (string) $ret['alphabet'];
        $alphabetUc = (string) $ret['alphabetUC'];
        if ($alphabet === '' || $alphabetUc === '') {
            throw new \InvalidArgumentException('Hyphenation alphabet is empty.');
        }
        if (mb_strlen($alphabet, $enc) !== mb_strlen($alphabetUc, $enc)) {
            throw new \InvalidArgumentException('Hyphenation upper and lower alphabets differ in length.');
        }
        if (!isset($ret['dict']) || !is_array($ret['dict']) || $ret['dict'] === array()) {
            throw new \InvalidArgumentException('Hyphenation dictionary is empty.');
        }

        $this->internal_encoding = $enc;
        $this->alphabet = $alphabet;
        $this->alphabet_uc = $alphabetUc;
        $this->translation = (isset($ret['trans']) && is_array($ret['trans'])) ? $ret['trans'] : array();
        $this->dictionary = $ret['dict'];
        $this->min_left_limit = (int) $ret['ll'];
        $this->min_right_limit = (int) $ret['rl'];
        $this->check_limits();
    }

    /**
     * @param string $encoding
     * @return string
     */
    private function marker($encoding)
    {
        $marker = $this->soft_hyphen;
        if ($marker === '') {
            return "\u{00AD}";
        }
        if (strcasecmp($encoding, 'UTF-8') === 0) {
            return $marker;
        }
        $converted = @iconv('UTF-8', $encoding, $marker);
        if (!is_string($converted) || $converted === '') {
            return $marker;
        }
        return $converted;
    }

    /**
     * @param string $encoding
     * @return string
     */
    private function normalize_encoding($encoding)
    {
        $encoding = trim((string) $encoding);
        if ($encoding === '' || preg_match('/^utf-?8$/i', $encoding) === 1) {
            return 'UTF-8';
        }
        return $encoding;
    }

    /**
     * @param string $chars
     * @return string
     */
    private function escape_class($chars)
    {
        return str_replace(
            array('\\', '/', '^', '-', ']'),
            array('\\\\', '\\/', '\\^', '\\-', '\\]'),
            $chars
        );
    }

    /**
     * @param string $dir
     * @param string $relative
     * @return string|null
     */
    private function resolve_existing($dir, $relative)
    {
        $full = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, (string) $relative);
        $real = realpath($full);
        if ($real === false || !is_file($real)) {
            return null;
        }
        return $real;
    }

    /**
     * @param string $dir
     * @param string $relative
     * @return string
     */
    private function resolve_target($dir, $relative)
    {
        $full = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, (string) $relative);
        $parent = realpath(dirname($full));
        if ($parent === false) {
            return $full;
        }
        return $parent . DIRECTORY_SEPARATOR . basename($full);
    }

    /**
     * @param string $configFile
     * @param array $ruleFiles
     * @return string
     */
    private function fingerprint($configFile, array $ruleFiles)
    {
        $hash = hash_init('sha256');
        hash_update($hash, self::VERSION);
        hash_update_file($hash, $configFile);
        foreach ($ruleFiles as $file) {
            hash_update($hash, "\0" . $file);
            hash_update_file($hash, $file);
        }
        return hash_final($hash);
    }

    /**
     * @param string|null $compiled
     * @param string $lang
     * @param string $fingerprint
     * @param bool $ignoreFingerprint
     * @return array|null
     */
    private function load_cache($compiled, $lang, $fingerprint, $ignoreFingerprint)
    {
        $candidates = array();
        if ($compiled !== null) {
            $candidates[] = $compiled;
        }
        $candidates[] = $this->temp_cache_path($lang);
        foreach ($candidates as $file) {
            $ret = $this->read_compiled($file);
            if ($this->cache_is_valid($ret, $fingerprint, $ignoreFingerprint)) {
                return $ret;
            }
        }
        return null;
    }

    /**
     * @param string|null $compiled
     * @param string $lang
     * @param array $ret
     */
    private function store_cache($compiled, $lang, array $ret)
    {
        $payload = serialize($ret);
        if ($compiled !== null && $this->write_file($compiled, $payload)) {
            return;
        }
        $this->write_file($this->temp_cache_path($lang), $payload);
    }

    /**
     * @param string $lang
     * @return string
     */
    private function temp_cache_path($lang)
    {
        $key = sha1(self::VERSION . '|' . $lang . '|' . __DIR__);
        return rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'hyphenation-' . $key . '.compiled';
    }

    /**
     * @param array|null $ret
     * @param string $fingerprint
     * @param bool $ignoreFingerprint
     * @return bool
     */
    private function cache_is_valid($ret, $fingerprint, $ignoreFingerprint)
    {
        if (!is_array($ret) || !isset($ret['ver'], $ret['dict'], $ret['alphabet'], $ret['alphabetUC'], $ret['enc'], $ret['ll'], $ret['rl'])) {
            return false;
        }
        if ($ret['ver'] !== self::VERSION || !is_array($ret['dict']) || $ret['dict'] === array()) {
            return false;
        }
        if (!$ignoreFingerprint && (!isset($ret['fingerprint']) || !hash_equals((string) $fingerprint, (string) $ret['fingerprint']))) {
            return false;
        }
        return true;
    }

    /**
     * @param string $filename
     * @return array|null
     */
    private function read_compiled($filename)
    {
        if (!is_file($filename)) {
            return null;
        }
        $raw = $this->read_file($filename);
        if (!is_string($raw) || strlen($raw) < 2 || $raw[0] !== 'a') {
            return null;
        }
        try {
            $ret = @unserialize($raw, array('allowed_classes' => false));
        } catch (\Throwable $e) {
            return null;
        }
        return is_array($ret) ? $ret : null;
    }

    /**
     * @param string $filename
     * @param string $data
     * @return bool
     */
    private function write_file($filename, $data)
    {
        $dir = dirname($filename);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }
        if (!is_writable($dir)) {
            return false;
        }
        return file_put_contents($filename, $data) !== false;
    }

    /**
     * @param string $filename
     * @return string|false
     */
    private function read_file($filename)
    {
        if (!is_file($filename) || !is_readable($filename)) {
            return false;
        }
        $data = file_get_contents($filename);
        if (!is_string($data)) {
            return false;
        }
        if (strncmp($data, "\xEF\xBB\xBF", 3) === 0) {
            $data = substr($data, 3);
        }
        return $data;
    }

    /**
     * @param string $instr
     * @param bool $is_unicode
     * @return string
     */
    protected function screen_special($instr, $is_unicode = false)
    {
        $patterns = array('/\n/', '/\s/', '/\'/', '/\x5C?\"/', '/\/\//', '/=/');
        $replaces = array('&SCREENEDLFEED&', '&SCREENEDSPACE&', '&SCREENSNQUOTE&', '&SCREENDBQUOTE&', '&SCREENDBSLASH&', '&SCREENEDEQUAL&');
        if ($is_unicode) {
            $patterns = $this->pattern2unicode($patterns);
        }
        $out = preg_replace($patterns, $replaces, $instr);
        return is_string($out) ? $out : $instr;
    }

    /**
     * @param string $instr
     * @param bool $is_unicode
     * @return string
     */
    private function clean_config($instr, $is_unicode = false)
    {
        $patterns = array('/\/\/.*$/m', '/^\s*/m', '/\s*$/m', '/(?<=\n)\n+/', '/\n$/', '/^\n/');
        $replaces = array('', '', '', '', '', '');
        if ($is_unicode) {
            $patterns = $this->pattern2unicode($patterns);
        }
        $out = preg_replace($patterns, $replaces, $this->unix_line_feeds($instr, $is_unicode));
        return is_string($out) ? $out : $instr;
    }

    /**
     * @param string $conf_file
     * @param bool $is_unicode
     * @return array|false
     */
    private function parse_config($conf_file, $is_unicode = false)
    {
        $in_file = $this->read_file($conf_file);
        if (!is_string($in_file) || $in_file === '') {
            return false;
        }
        return $this->parse_config_str($in_file, $is_unicode);
    }

    /**
     * @param string $str
     * @param bool $is_unicode
     * @return array
     */
    private function parse_config_str($str, $is_unicode = false)
    {
        $patterns = array(
            '/&SCREENEDSPACE&/',
            '/&SCREENEDLFEED&/',
            '/&SCREENSNQUOTE&/',
            '/&SCREENDBQUOTE&/',
            '/&SCREENDBSLASH&/',
            '/&SCREENEDEQUAL&/',
        );
        $replaces = array(' ', "\n", '\'', '"', '//', '=');
        if ($is_unicode) {
            $patterns = $this->pattern2unicode($patterns);
        }

        $str = $this->unix_line_feeds($str, $is_unicode);
        $empty = '/(?<=\=)\s*\'\'/';
        $quoted = '/(?<!\x5C)\'(.*[^\x5C])\'/Us';
        if ($is_unicode) {
            $empty = $this->pattern2unicode($empty);
            $quoted = $this->pattern2unicode($quoted);
        }
        $str = preg_replace($empty, '', $str);
        $str = preg_replace_callback($quoted, function ($in) use ($is_unicode) {
            return $this->screen_special($in[1], $is_unicode);
        }, $str);
        if (!is_string($str)) {
            return array();
        }
        $str = $this->clean_config($str, $is_unicode);

        $ret = array();
        foreach (explode("\n", $str) as $val) {
            if ($val === '') {
                continue;
            }
            $pair = explode('=', $val, 2);
            $key = trim($pair[0]);
            if ($key === '') {
                continue;
            }
            if (!isset($pair[1])) {
                $ret[$key][] = true;
                continue;
            }
            $value = preg_replace($patterns, $replaces, trim($pair[1]));
            $ret[$key][] = is_string($value) ? $value : trim($pair[1]);
        }
        return $ret;
    }

    /**
     * @param array|string $pattern
     * @param string $from_enc
     * @param int $flags
     * @return array|string
     */
    private function pattern2unicode($pattern, $from_enc = 'ISO-8859-1', $flags = self::P2U_ALL)
    {
        if (is_array($pattern)) {
            $ret = array();
            foreach ($pattern as $key => $val) {
                $ret[$key] = $this->pattern2unicode($val, $from_enc, $flags);
            }
            return $ret;
        }
        if (!is_string($pattern)) {
            return $pattern;
        }

        $ret = ($flags & self::P2U_RECODE) ? @iconv($from_enc, 'UTF-8', $pattern) : $pattern;
        if (!is_string($ret)) {
            $ret = $pattern;
        }
        if ($flags & self::P2U_PROPERTIES) {
            $sources = array(
                '/(?<!(?<!(?<!\x5C)\x5C)\x5C)\x5Cd/',
                '/(?<!(?<!(?<!\x5C)\x5C)\x5C)\x5CD/',
                '/(?<!(?<!(?<!\x5C)\x5C)\x5C)\x5Cw/',
                '/(?<!(?<!(?<!\x5C)\x5C)\x5C)\x5CW/',
                '/(?<!(?<!(?<!\x5C)\x5C)\x5C)\x5Cs/',
                '/(?<!(?<!(?<!\x5C)\x5C)\x5C)\x5CS/',
            );
            $targets = array('\p{Nd}', '\P{Nd}', '\p{L}', '\P{L}', '\p{Zs}', '\P{Zs}');
            $replaced = preg_replace($sources, $targets, $ret);
            if (is_string($replaced)) {
                $ret = $replaced;
            }
        }
        if ($flags & self::P2U_MODIFIER) {
            $ret .= 'u';
        }
        return $ret;
    }

    /**
     * @param string $str
     * @param bool $is_unicode
     * @return string
     */
    private function unix_line_feeds($str, $is_unicode = false)
    {
        $out = preg_replace('/\r\n?/' . ($is_unicode ? 'u' : ''), "\n", $str);
        return is_string($out) ? $out : $str;
    }

    /**
     * @param string $str
     * @param int $l
     * @param string|null $encoding
     * @return array
     */
    private function mb_str_split($str, $l = 1, $encoding = null)
    {
        if ($encoding === null || $encoding === '') {
            $encoding = $this->internal_encoding !== '' ? $this->internal_encoding : 'UTF-8';
        }
        $str = (string) $str;
        if ($l < 1) {
            return array();
        }
        $ret = array();
        $len = mb_strlen($str, $encoding);
        for ($i = 0; $i < $len; $i += $l) {
            $ret[] = mb_substr($str, $i, $l, $encoding);
        }
        return $ret;
    }

    /**
     * @param string $string
     * @param string $replacement
     * @param int $start
     * @param int|null $length
     * @param string|null $encoding
     * @return string
     */
    private function mb_substr_replace($string, $replacement, $start, $length = null, $encoding = null)
    {
        if ($encoding === null || $encoding === '') {
            $encoding = $this->internal_encoding !== '' ? $this->internal_encoding : 'UTF-8';
        }
        $string_length = mb_strlen($string, $encoding);
        if ($start < 0) {
            $start = max(0, $string_length + $start);
        } elseif ($start > $string_length) {
            $start = $string_length;
        }
        if ($length === null || $length > $string_length) {
            $length = $string_length;
        } elseif ($length < 0) {
            $length = max(0, $string_length - $start + $length);
        }
        if (($start + $length) > $string_length) {
            $length = $string_length - $start;
        }
        return mb_substr($string, 0, $start, $encoding)
            . $replacement
            . mb_substr($string, $start + $length, $string_length - $start - $length, $encoding);
    }
}
