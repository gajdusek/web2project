<?php /* $Id$ $URL$ */

/**
 *	@package web2project
 *	@subpackage core
 *	@version $Revision$
 */

require_once W2P_BASE_DIR . '/lib/adodb/adodb.inc.php';

class w2p_Core_UpgradeManager {
    private $action = '';

    private $configDir = '';
    private $configFile = '';
    private $uploadDir = '';
    private $languageDir = '';
    private $tempDir = '';
    private $configOptions = array();
    private $updatesApplied = array();

    public function getActionRequired() {
        global $w2Pconfig;

        if ($this->action == '') {
            $this->_prepareConfiguration();
            if (!file_exists($this->configFile) || filesize($this->configFile) == 0) {
                $this->action = 'install';
            } else {
                require_once $this->configFile;
                if (isset($dPconfig)) {
                    $this->configOptions = $dPconfig;
                    $this->action = 'conversion';
                } elseif (isset($w2Pconfig)) {
                    $this->configOptions = $w2Pconfig;
                    $this->action = 'upgrade';
                } else {
                    /*
                     *  This  case should never be reached because if there is a config.
                     * php file, it should load either the $dPconfig or $w2Pconfig
                     * depending on whether it's an conversion or upgrade respectively.
                     * If we reach here, we have this strange situation of a mostly
                     * "configured" system that doesn't have the configuration values
                     * required.
                     */
                    $this->action = 'install';
                }
            }
        }
        return $this->action;
    }
    public function getConfigDir() {
        return $this->configDir;
    }
    public function getConfigFile() {
        return $this->configFile;
    }
    public function getUploadDir() {
        return $this->uploadDir;
    }
    public function getLanguageDir() {
        return $this->languageDir;
    }
    public function getTempDir() {
        return $this->tempDir;
    }
    public function getConfigOptions() {
        return $this->configOptions;
    }
    private function _setConfigOptions($dbConfig) {
        $this->configOptions = $dbConfig;
    }
    public function upgradeSystem() {
        $allErrors = array();
        set_time_limit(0);
        //TODO: add support for database prefixes

        $dbConn = $this->_openDBConnection();

        if ($dbConn) {
            $currentVersion = $this->_getDatabaseVersion($dbConn);
            $migrations = $this->_getMigrations();

            if ($currentVersion < count($migrations)) {
                foreach ($migrations as $update) {
                    $myIndex = (int) substr($update, 0, 3);
                    if ($myIndex > $currentVersion) {
                        $this->updatesApplied[] = $update;
                        $errorMessages = $this->_applySQLUpdates($update, $dbConn);
                        $allErrors = array_merge($allErrors, $errorMessages);
                        $sql = "INSERT INTO w2pversion (db_version, last_db_update) VALUES ($myIndex, now())";
                        $dbConn->Execute($sql);
                    }
                }
            }
        } else {
            $allErrors[] = 'Update failed. Database connection was not found.';
        }

        return $allErrors;
    }
    public function getUpdatesApplied() {
        return $this->updatesApplied;
    }
    public function convertDotProject() {
        $dpVersion = '';
        set_time_limit(0);

        $allErrors = array();
        $dbConn = $this->_openDBConnection();
        $sql = "SELECT * FROM dpversion ORDER BY db_version DESC";
        $res = $dbConn->Execute($sql);
        if ($res && $res->RecordCount() > 0) {
            $dpVersion = $res->fields['code_version'];
        }

        switch ($dpVersion) {
            case '2.0':
                $errorMessages = $this->_applySQLUpdates('dp20_to_201.sql', $dbConn);
                $allErrors = array_merge($allErrors, $errorMessages);
            case '2.0.1':
                $errorMessages = $this->_applySQLUpdates('dp201_to_202.sql', $dbConn);
                $allErrors = array_merge($allErrors, $errorMessages);
            case '2.0.2':
            case '2.0.3':
            case '2.0.4':
            case '2.1-rc1':
                $errorMessages = $this->_applySQLUpdates('dp21rc1_to_21rc2.sql', $dbConn);
                $allErrors = array_merge($allErrors, $errorMessages);
            case '2.1-rc2':
            case '2.1':
            case '2.1.1':
            case '2.1.2':
            case '2.1.3':
            case '2.1.4':
                $errorMessages = $this->_applySQLUpdates('dp_to_w2p1.sql', $dbConn);
                $allErrors = array_merge($allErrors, $errorMessages);

                $recordsUpdated = $this->_scrubDotProjectData($dbConn);

                $errorMessages = $this->_applySQLUpdates('dp_to_w2p2.sql', $dbConn);
                $allErrors = array_merge($allErrors, $errorMessages);

                $errorMessages = $this->upgradeSystem($dbConn);
                $allErrors = array_merge($allErrors, $errorMessages);

                break;
            default:
                $allErrors['version_fail'] = "Unfortunately, we can't determine which version of dotProject you're using.  To be safe, we're not going to do anything.";
                $allErrors[] = "If you are using dotProject 1.x, please use their methods to upgrade to dotProject v2.x before you go any further.";
                $allErrors[] = "If you really are using dotProject 2.x, please check to see that you are on an official release and/or contact the web2project forums.";
        }

        return $allErrors;
    }
    public function createConfigString($dbConfig) {
        $configFile = file_get_contents('../includes/config-dist.php');
        $configFile = str_replace('[DBTYPE]', $dbConfig['dbtype'], $configFile);
        $configFile = str_replace('[DBHOST]', $dbConfig['dbhost'], $configFile);
        $configFile = str_replace('[DBNAME]', $dbConfig['dbname'], $configFile);
        $configFile = str_replace('[DBUSER]', $dbConfig['dbuser'], $configFile);
        $configFile = str_replace('[DBPASS]', $dbConfig['dbpass'], $configFile);
        $configFile = str_replace('[DBCONN_CHARSET]', $dbConfig['dbconn_charset'] ? 'true' : 'false', $configFile);
        $configFile = str_replace('[DBPREFIX]', '', $configFile);
        //TODO: add support for configurable persistent connections
        $configFile = trim($configFile);

        return $configFile;
    }
    public function getMaxFileUpload() {
        $maxfileuploadsize = min($this->_getIniSize(ini_get('upload_max_filesize')), $this->_getIniSize(ini_get('post_max_size')));
        $memory_limit = $this->_getIniSize(ini_get('memory_limit'));
        if ($memory_limit > 0 && $memory_limit < $maxfileuploadsize) $maxfileuploadsize = $memory_limit;
        // Convert back to human readable numbers
        if ($maxfileuploadsize > 1048576) {
            $maxfileuploadsize = (int)($maxfileuploadsize / 1048576) . 'M';
        } else if ($maxfileuploadsize > 1024) {
            $maxfileuploadsize = (int)($maxfileuploadsize / 1024) . 'K';
        }

        return $maxfileuploadsize;
    }
    public function testDatabaseCredentials($w2Pconfig) {
        $result = false;

        $this->_setConfigOptions($w2Pconfig);

        $dbConn = $this->_openDBConnection();
        if ($dbConn->_errorMsg == '') {
            $result = true;
        }

        return $result;
    }
    
    /**
     * Check if it is safe to force UTF8 as database connection charset.
     * <b>Before calling this method w2p_Core_UpgradeManager::$configOptions must be properly set.</b>
     * @param bool $after_db_conversion Set this to TRUE if you want to know whether converting data on database to UTF8 will help.
     * @return bool Boolean represantaion of the answer to one of following questions:
     *	 - Is it safe to force UTF8 as database connection charset right now? This question is answered by default.
     *	 - Will it be safe, after the data on database will be converted to UTF8? This question will be answered if the $after_db_conversion parameter is set to TRUE.
     * @uses _getDatabaseVersion() to check if database is empty, it is considered empty if version 0 is returned
     * @uses _openDBConnection(), this method must NEVER set any database connection charset.
     */
    public function DBConnectionCharsetSafetyCheck( $after_db_conversion = false ) {
        $result = false;
        $result_after_db_conversion = false;

        /*
         * prerequisites check
         */
        
        // check if $configOptions are properly set
        if ( empty( $this->configOptions ) ) { // should never happen
            $this->getActionRequired();
            if ( empty( $this->configOptions ) ) {
                trigger_error('Configuration is not set', E_USER_WARNING);
                return false;
            }
        }
        //  check if dbconn_charset is already set
        if ( $this->configOptions['dbconn_charset'] and $after_db_conversion == false ) {
            return true;
        }
        //check if database connection is working
        $dbConn = $this->_openDBConnection();
        if ( ! $dbConn->_errorMsg == '') {
            trigger_error('Database connection failed', E_USER_NOTICE);
            return false;
        }
        
        /*
         * Check if charset for database connection may be safely set
         */ 
 
        $empty_database = ( $this->_getDatabaseVersion( $dbConn ) == 0 );  // is database empty?

        switch ( $this->configOptions['dbtype'] ) {
            case 'mysql' :
                $dbver = preg_replace( '/[^0-9.].*/', '', mysql_get_server_info( $dbConn->_connectionID ) );
                if ( version_compare( $dbver, '5.0.7', '>=' ) ) { 
                    if ( $empty_database or mysql_client_encoding() == 'utf8' ) $result = true;
                    else $result_after_db_conversion = true;
                }
                break;
            default:
                // database not supported
        }
        
        $dbConn->Close();
        
        return $after_db_conversion ? $result_after_db_conversion : $result;
    }
    
    public function upgradeRequired() {
        $dbConn = $this->_openDBConnection();
        return (count($this->_getMigrations()) > $this->_getDatabaseVersion($dbConn));
    }

    private function _getIniSize($val) {
       $val = trim($val);
       if (strlen($val <= 1)) return $val;
       $last = $val{strlen($val)-1};
       switch($last) {
           case 'k':
           case 'K':
               return (int) $val * 1024;
               break;
           case 'm':
           case 'M':
               return (int)   $val * 1048576;
               break;
           default:
               return $val;
       }
    }
    private function _getMigrations() {
        $migrations = array();

        $path = W2P_BASE_DIR.'/install/sql/'.$this->configOptions['dbtype'];
        $dir_handle = @opendir($path) or die("Unable to open $path");

        while ($file = readdir($dir_handle)) {
           $migrationNumber = (int) substr($file, 0, 3);
           if ($migrationNumber > 0) {
             $migrations[$migrationNumber] = $file;
           }
        }
        sort($migrations);
        return $migrations;
    }
    private function _getDatabaseVersion($dbConn) {

        $sql = "SELECT max(db_version) FROM w2pversion";
        $res = $dbConn->Execute($sql);

        if ($res && $res->RecordCount() > 0) {
            $currentVersion = $res->fields[0];
        } else {
            $currentVersion = 0;
        }

        return $currentVersion;
    }
    private function _prepareConfiguration() {
        $this->configDir = W2P_BASE_DIR.'/includes';
        $this->configFile = W2P_BASE_DIR.'/includes/config.php';
        $this->uploadDir = W2P_BASE_DIR.'/files';
        $this->languageDir = W2P_BASE_DIR.'/locales/en';
        $this->tempDir = W2P_BASE_DIR.'/files/temp';
    }
    private function _applySQLUpdates($sqlfile, $dbConn) {
        $sqlfile = W2P_BASE_DIR.'/install/sql/'.$this->configOptions['dbtype'].'/'.$sqlfile;
        if (!file_exists($sqlfile) || filesize($sqlfile) == 0) {
            return array();
        }

        $query = fread(fopen($sqlfile, "r"), filesize($sqlfile));
        $pieces  = $this->_splitSQLUpdates($query);
        $pieceCount = count($pieces);
        $errorMessages = array();

        for ($i=0; $i < $pieceCount; $i++) {
            $pieces[$i] = trim($pieces[$i]);
            if(!empty($pieces[$i]) && $pieces[$i] != "#") {
                /*
                 * While this seems like a good place to use the core classes, it's
                 * really not.  With all of the dependencies, it just gets to be a huge
                 * pain and ends up loading all kinds of unnecessary stuff.
                 */
                if (strpos($pieces[$i], '[ADMINPASS]') !== false) {
                    $pieces[$i] = str_replace('[ADMINPASS]', $this->configOptions['adminpass'], $pieces[$i]);
                }
                if (!$result = $dbConn->Execute($pieces[$i])) {
        $errorMessage = $dbConn->ErrorMsg();
        /*
         * TODO: I'm not happy with this solution but have yet to come up
         * 	with another way of solving it...
         */
        if (strpos($errorMessage, 'Duplicate column name') === false &&
          strpos($errorMessage, 'column/key exists') === false &&
          strpos($errorMessage, 'Multiple primary key defined') &&
          strpos($errorMessage, 'Duplicate key name') ) {

          $dbErr = true;
          $errorMessages[] = $errorMessage;
        }
                }
            }
        }

        return $errorMessages;
    }
    private function _splitSQLUpdates($sql) {
        return explode(';', $sql);
    }
    private function _openDBConnection() {
        /*
         * While this seems like a good place to use the core classes, it's
         * really not.  With all of the dependencies, it just gets to be a huge
         * pain and ends up loading all kinds of unnecessary stuff.
         */
        $db = false;

        try {
            $db = NewADOConnection($this->configOptions['dbtype']);
            if(!empty($db)) {
              $dbConnection = $db->Connect($this->configOptions['dbhost'], $this->configOptions['dbuser'], $this->configOptions['dbpass']);
              if ($dbConnection) {
                $existing_db = $db->SelectDB($this->configOptions['dbname']);
                if (!$existing_db) {
                  $db->_errorMsg = 'This database user does not have rights to the database.';
                }
              }
            } else {
                $dbConnection = false;
            }
        } catch (Exception $exc) {
            echo 'Your database credentials do not work.';
        }
        return $db;
    }
    private function _scrubDotProjectData($dbConn) {
        /*
         * While this seems like a good place to use the core classes, it's
         * really not.  With all of the dependencies, it just gets to be a huge
         * pain and ends up loading all kinds of unnecessary stuff.
         */
        rmdir(W2P_BASE_DIR.'/db/');
        $recordsUpdated = 0;

        $sql = "SELECT * FROM sysvals WHERE sysval_value like '%|%' ORDER BY sysval_id ASC";
        $res = $dbConn->Execute($sql);
        if ($res->RecordCount() > 0) {
            while (!$res->EOF) {
                $fields = $res->fields;

                $sysvalId = $fields['sysval_id'];
                $sysvalKeyId = $fields['sysval_key_id'];
                $sysvalTitle = $fields['sysval_title'];
                $values = explode("\n", $fields['sysval_value']);
                foreach ($values as $syskey) {
                    $sysvalValId = substr($syskey, 0, strpos($syskey, '|'));
                    $sysvalValue = substr(trim(' '.$syskey.' '), strpos($syskey, '|') + 1);
                    $sql = "INSERT INTO sysvals (sysval_key_id, sysval_title, sysval_value, sysval_value_id) " .
                            "VALUES ($sysvalKeyId, '$sysvalTitle', '$sysvalValue', $sysvalValId)";
                    $dbConn->Execute($sql);
                }
                $recordsUpdated++;
                $sql = "DELETE FROM sysvals WHERE sysval_id = $sysvalId";
                $dbConn->Execute($sql);
                $res->MoveNext();
            }
        }
        return $recordsUpdated;
    }
}
