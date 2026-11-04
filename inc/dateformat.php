<?php

// Keep existing strftime-style settings without using deprecated strftime().
function tinyibFormatDate($format, $timestamp) {
	$date = (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
	return preg_replace_callback('/%([_0-]?)([EO]?)([%a-zA-Z+])/', function ($match) use ($date, $timestamp) {
		$code = $match[3];
		$patterns = array('a' => 'D', 'A' => 'l', 'b' => 'M', 'h' => 'M', 'B' => 'F',
			'd' => 'd', 'e' => 'j', 'm' => 'm', 'y' => 'y', 'Y' => 'Y', 'g' => 'y', 'G' => 'o',
			'H' => 'H', 'k' => 'G', 'I' => 'h', 'l' => 'g', 'M' => 'i', 'S' => 's',
			'p' => 'A', 'P' => 'a', 'u' => 'N', 'w' => 'w', 'V' => 'W', 'z' => 'O', 'Z' => 'T',
			'D' => 'm/d/y', 'F' => 'Y-m-d', 'R' => 'H:i', 'T' => 'H:i:s', 'r' => 'h:i:s A',
			'c' => 'D M j H:i:s Y', 'x' => 'm/d/y', 'X' => 'H:i:s');
		if ($code === '%') return '%';
		if ($code === 'n') return "\n";
		if ($code === 't') return "\t";
		if ($code === 's') return (string) (int) $timestamp;
		if ($code === 'C') return sprintf('%02d', intdiv((int) $date->format('Y'), 100));
		if ($code === 'j') return sprintf('%03d', (int) $date->format('z') + 1);
		if ($code === 'g') return substr($date->format('o'), -2);
		if ($code === 'U' || $code === 'W') {
			$weekday = (int) $date->format($code === 'U' ? 'w' : 'N') - ($code === 'W' ? 1 : 0);
			return sprintf('%02d', intdiv((int) $date->format('z') + 7 - $weekday, 7));
		}
		if (!isset($patterns[$code])) return $match[0];
		$value = $date->format($patterns[$code]);
		// Localize names when intl is available; English is the portable fallback.
		$localized = array('a' => 'EEE', 'A' => 'EEEE', 'b' => 'MMM', 'h' => 'MMM', 'B' => 'MMMM', 'p' => 'a', 'P' => 'a');
		if (isset($localized[$code]) && class_exists('IntlDateFormatter') && defined('TINYIB_LOCALE') && TINYIB_LOCALE !== '') {
			$formatter = new IntlDateFormatter(TINYIB_LOCALE, IntlDateFormatter::NONE, IntlDateFormatter::NONE,
				$date->getTimezone()->getName(), IntlDateFormatter::GREGORIAN, $localized[$code]);
			$result = $formatter->format((int) $timestamp);
			if ($result !== false) $value = $result;
		}
		if ($match[1] === '-') return ltrim($value, '0 ') ?: '0';
		if ($match[1] === '_' || ($match[1] === '' && ($code === 'e' || $code === 'k' || $code === 'l'))) {
			return str_pad(ltrim($value, '0 '), 2, ' ', STR_PAD_LEFT);
		}
		return $value;
	}, $format);
}
