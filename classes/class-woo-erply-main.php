<?php
/**
 * Global plugin settings
 */

class Woo_Erply_Main {
    public $textdomain;
    public $endpoint;
    public static $logs_path = WP_CONTENT_DIR . '/logs/woo_erply_sync_logs/';
    public static $sync_logs_file_name;

    /**
     * @param $args
     */
    public function __construct( $args ) {
        if ( !empty( $args["textdomain"] ) ) $this->textdomain = $args["textdomain"];
        if ( !empty( $args["endpoint"] ) ) $this->endpoint = $args["endpoint"];

        if ( !is_dir( self::$logs_path ) ) {
			wp_mkdir_p( self::$logs_path );
        }

        if ( is_dir( self::$logs_path ) ) {
            self::$sync_logs_file_name = self::get_log_file_name_by_date( date("d-m-Y") );

            if ( !file_exists( self::$sync_logs_file_name ) ) {
                file_put_contents( self::$sync_logs_file_name, "" );
            }
        }

        if ( !is_writable( self::$logs_path ) ) {
            $notice = '<div class="notice notice-warning is-dismissible"><p>' . 'Can not write to "' . self::$sync_logs_file_name . '". Please check permissions and make folder writable to enable sync logs' . '</p></div>';

            add_action('erply_notices', function () use ($notice) {
                echo $notice;
            });
        }
    }

    /**
     * Get real user IP
     *
     * @return string
     */
    public function get_user_real_ip(){
        $ip = '';

        if ( !empty( $_SERVER["HTTP_X_FORWARDED_FOR"] ) ) {
            $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
        } else if ( !empty( $_SERVER["HTTP_X_REAL_IP"] ) ) {
            $ip = $_SERVER["HTTP_X_REAL_IP"];
        } else if ( !empty( $_SERVER["REMOTE_ADDR"] ) ) {
            $ip = $_SERVER["REMOTE_ADDR"];
        }

        return $ip;
    }

    /**
     * Add a record to the logs file
     *
     * @param $data
     * @param $append - true to append data or false for replace
     */
    public static function write_to_log_file( $data, $append = true ) {
        if ( is_writable( self::$sync_logs_file_name ) ) {
            $flags = LOCK_EX;

            if ( $append ) {
                $flags = $flags | FILE_APPEND;
            }

            file_put_contents( self::$sync_logs_file_name, $data . "\n", $flags );
        }
    }

    /**
     * Get contents of logs file
     *
     * @return string
     */
    public static function get_log_file_contents() {
        $contents = "";
        $lines    = [];

        if ( is_readable( self::$sync_logs_file_name ) ) {
            $handle = fopen( self::$sync_logs_file_name, "r" );

            if ( $handle ) {
                while ( ( $line = fgets( $handle ) ) !== false) {
                    // Put each log line into array
                    $lines[] = $line;
                    // Remove first element from array to keep only last 100 lines
                    if ( count( $lines ) > 100 ) {
                        array_shift( $lines );
                    }
                }

                fclose( $handle );
                // Combine all log lines into one string.
                $contents = implode( "", $lines );
            } else {
                // Output notice if could not open file
                $notice = '<div class="notice notice-warning is-dismissible"><p>' . 'Unable to open "' . self::$sync_logs_file_name . '"</p></div>';

                add_action('erply_notices', function () use ($notice) {
                    echo $notice;
                });
            }
        }

        return $contents;
    }

    public static function download_sync_logs( $option = "day" ){
        $file        = '';
        $delete_file = false;

        switch ( $option ) {
            case "day":
                $file = self::$sync_logs_file_name;
                break;
            case "week":
                $file = self::zip_all_sync_logs_files( true );
                $delete_file = true;
                break;
            case "history":
                $file = self::zip_all_sync_logs_files();
                $delete_file = true;
                break;
        }

        if ( !empty( $file ) ) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename='.basename( $file ));
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize( $file ));
            readfile( $file );
            // Delete file after download. Useful when archive was created and it is not needed anymore
            if ( !empty( $delete_file ) ) {
				wp_delete_file( $file );
            }

            exit();
        }
    }

    /**
     * Return erply woo sync logs file name by date
     * Date format should be "d-m-Y"
     *
     * @param $date
     * @return string
     */
    public static function get_log_file_name_by_date( $date ){
        return self::$logs_path . "woo_erply_sync_log_".$date.".txt";
    }

    /**
     * Archive sync log files into zip file
     *
     * @param bool $weekly - If true add log file for last 7 days only
     * @return string - archive file name
     */
    public static function zip_all_sync_logs_files( $weekly = false ){
        // Get real path for sync logs folder
        $root_path    = realpath( self::$logs_path );
        $archive_name = self::$logs_path . "woo_erply_sync_logs_history.zip";

        // Initialize archive object
        $zip = new ZipArchive();
        $zip->open( $archive_name, ZipArchive::CREATE | ZipArchive::OVERWRITE );

        // Create recursive directory iterator
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $root_path ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        if ( $weekly ) {
            $time       = time();
            $collection = [
                self::get_log_file_name_by_date( date("d-m-Y") ),
                self::get_log_file_name_by_date( date("d-m-Y", $time - DAY_IN_SECONDS ) ),
                self::get_log_file_name_by_date( date("d-m-Y", $time - 2*DAY_IN_SECONDS ) ),
                self::get_log_file_name_by_date( date("d-m-Y", $time - 3*DAY_IN_SECONDS ) ),
                self::get_log_file_name_by_date( date("d-m-Y", $time - 4*DAY_IN_SECONDS ) ),
                self::get_log_file_name_by_date( date("d-m-Y", $time - 5*DAY_IN_SECONDS ) ),
                self::get_log_file_name_by_date( date("d-m-Y", $time - 6*DAY_IN_SECONDS ) ),
            ];
        }

        foreach ( $files as $name => $file ) {
            if ( $weekly && !in_array( $name, $collection ) ) {
                continue;
            }
            // Skip directories (they would be added automatically)
            if ( !$file->isDir() ) {
                // Get real and relative path for current file
                $file_path     = $file->getRealPath();
                $relative_path = substr( $file_path, strlen( $root_path ) + 1 );
                // Add current file to archive
                $zip->addFile( $file_path, $relative_path );
            }
        }

        // Zip archive will be created only after closing object
        $zip->close();

        return $archive_name;
    }


}
