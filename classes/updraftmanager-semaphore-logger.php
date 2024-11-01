<?php

if (!defined('UDMANAGER_DIR')) die('Security check');

class UpdraftManager_Semaphore_Logger {
	
	// A callable to be called with a log line as the parameter
	protected $logger;
	
	/**
	 * Constructor for the logger object.
	 *
	 * @param Callable|'error_log' $logger	 - a callable to be called with a log line as the parameter
	 */
	public function __construct($logger = 'error_log') {
		$this->logger = $logger;
	}

	
	/**
	 * Log information
	 * 
	 * @param String $line - the line to log
	 * @param String $level - the log level: notice, warning, error
	 */
	public function log($line, $level) {
		$settings = UpdraftManager_Options_Extended::get_settings();
		$debugmode = empty($settings['debugmode']) ? false : true;
		if (is_callable($this->logger) && ('error' == $level || 'warning' == $level || ($debugmode && defined('WP_DEBUG') && WP_DEBUG)))  return call_user_func($this->logger, $line);
	}

}
