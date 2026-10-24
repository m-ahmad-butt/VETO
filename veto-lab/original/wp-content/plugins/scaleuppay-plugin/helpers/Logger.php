<?php

class CleffoLogger {
    private static $instance = null;
    private $logsDir;
    private $useWCLogger = true;
    private $wclogger;
    
    private function __construct() {
        $this->logsDir = dirname(__DIR__) . '/logs';
        if($this->useWCLogger && function_exists('wc_get_logger')){
            $this->wclogger = wc_get_logger();
        }
        
        // Create logs directory if it doesn't exist
        if (!file_exists($this->logsDir)) {
            mkdir($this->logsDir, 0755, true);
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function info($message, $context = []) {
        if($this->useWCLogger){
            $this->wclogger->info($message, array('source' => 'cleffo-payment'));
        }
        $this->log('INFO', $message, $context);
    }
    
    public function error($message, $context = []) {
        if($this->useWCLogger){
            $this->wclogger->error($message, array('source' => 'cleffo-payment'));
        }
        $this->log('ERROR', $message, $context);
    }
    
    public function debug($message, $context = []) {
        if($this->useWCLogger){
            $this->wclogger->debug($message, array('source' => 'cleffo-payment'));
        }
        $this->log('DEBUG', $message, $context);
    }
    
    private function getCurrentLogFile() {
        $date = date('Y_m_d');
        return $this->logsDir . '/cleffo_' . $date . '.log';
    }
    
    private function log($level, $message, $context = []) {
        try {
            $logFile = $this->getCurrentLogFile();
            
            // Ensure the log file exists and is writable
            if (!file_exists($logFile)) {
                touch($logFile);
                chmod($logFile, 0644);
            }
            
            $timestamp = date('Y-m-d H:i:s');
            $contextString = !empty($context) ? json_encode($context, JSON_PRETTY_PRINT) : '';
            
            $logEntry = sprintf(
                "[%s] %s: %s %s\n",
                $timestamp,
                $level,
                $message,
                $contextString
            );
            
            if (!is_writable($logFile)) {
                error_log("Cleffo Logger: Log file is not writable - " . $logFile);
                return;
            }
            
            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            error_log("Cleffo Logger: Failed to write to log - " . $e->getMessage());
        }
    }

    /**
     * Absolute path to the plugin logs directory.
     *
     * @return string
     */
    public function getLogsDir() {
        return $this->logsDir;
    }

    /**
     * List available cleffo_*.log files, newest first.
     *
     * @return array[]
     */
    public function listLogFiles() {
        $files = glob( $this->logsDir . '/cleffo_*.log' );
        if ( ! is_array( $files ) ) {
            return array();
        }

        rsort( $files );

        $list = array();
        foreach ( $files as $path ) {
            $name = basename( $path );
            if ( ! preg_match( '/^cleffo_\d{4}_\d{2}_\d{2}\.log$/', $name ) ) {
                continue;
            }

            $list[] = array(
                'name'     => $name,
                'path'     => $path,
                'size'     => filesize( $path ),
                'modified' => filemtime( $path ),
            );
        }

        return $list;
    }

    /**
     * Resolve a safe log file path by basename.
     *
     * @param string $filename
     * @return string|false
     */
    public function resolveLogFile( $filename ) {
        $filename = basename( (string) $filename );
        if ( ! preg_match( '/^cleffo_\d{4}_\d{2}_\d{2}\.log$/', $filename ) ) {
            return false;
        }

        $path = $this->logsDir . '/' . $filename;
        if ( ! is_file( $path ) ) {
            return false;
        }

        $real_dir  = realpath( $this->logsDir );
        $real_file = realpath( $path );
        if ( ! $real_dir || ! $real_file ) {
            return false;
        }

        $real_dir  = wp_normalize_path( $real_dir );
        $real_file = wp_normalize_path( $real_file );
        if ( 0 !== strpos( $real_file, trailingslashit( $real_dir ) ) && $real_file !== $real_dir . '/' . $filename ) {
            return false;
        }

        return $real_file;
    }

    /**
     * Read lines from a log file (tail by default for large files).
     *
     * @param string $filename
     * @param array  $args {
     *     @type string $level  INFO|ERROR|DEBUG|all
     *     @type string $search Free-text search
     *     @type int    $limit  Max lines to return
     *     @type string $order  newest|oldest
     * }
     * @return array{file:string,total_lines:int,returned:int,lines:array}|WP_Error-like array on failure
     */
    public function readLogFile( $filename, $args = array() ) {
        $path = $this->resolveLogFile( $filename );
        if ( ! $path ) {
            return array(
                'error' => 'Log file not found.',
            );
        }

        $args = wp_parse_args(
            $args,
            array(
                'level'  => 'all',
                'search' => '',
                'limit'  => 500,
                'order'  => 'newest',
            )
        );

        $level  = strtoupper( sanitize_text_field( $args['level'] ) );
        $search = (string) $args['search'];
        $limit  = max( 50, min( 5000, absint( $args['limit'] ) ) );
        $order  = ( 'oldest' === $args['order'] ) ? 'oldest' : 'newest';

        $raw_lines = file( $path, FILE_IGNORE_NEW_LINES );
        if ( false === $raw_lines ) {
            return array(
                'error' => 'Unable to read log file.',
            );
        }

        $total = count( $raw_lines );
        $matched = array();

        foreach ( $raw_lines as $index => $line ) {
            if ( '' === trim( $line ) ) {
                continue;
            }

            $parsed_level = '';
            if ( preg_match( '/^\[[^\]]+\]\s+([A-Z]+):/', $line, $m ) ) {
                $parsed_level = $m[1];
            }

            if ( 'ALL' !== $level && '' !== $level && $parsed_level !== $level ) {
                continue;
            }

            if ( '' !== $search && false === stripos( $line, $search ) ) {
                continue;
            }

            $matched[] = array(
                'num'   => $index + 1,
                'level' => $parsed_level ? $parsed_level : 'INFO',
                'line'  => $line,
            );
        }

        if ( 'newest' === $order ) {
            $matched = array_reverse( $matched );
        }

        $returned_lines = array_slice( $matched, 0, $limit );

        return array(
            'file'        => basename( $path ),
            'size'        => filesize( $path ),
            'total_lines' => $total,
            'matched'     => count( $matched ),
            'returned'    => count( $returned_lines ),
            'lines'       => $returned_lines,
        );
    }

    /**
     * Empty a log file (keeps the file).
     *
     * @param string $filename
     * @return bool
     */
    public function clearLogFile( $filename ) {
        $path = $this->resolveLogFile( $filename );
        if ( ! $path || ! is_writable( $path ) ) {
            return false;
        }

        return false !== file_put_contents( $path, '' );
    }
} 