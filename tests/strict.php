<?php
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
	// Respect deliberate I/O probes, but catch even suppressed deprecations.
	if (!(error_reporting() & $severity) && !($severity & (E_DEPRECATED | E_USER_DEPRECATED))) return false;
	throw new ErrorException($message, 0, $severity, $file, $line);
});
