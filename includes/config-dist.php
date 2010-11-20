<?php
if (!defined('W2P_BASE_DIR')) {
	die('You should not access this file directly.');
}

/*
    Copyright (c) 2007-2010 The web2Project Development Team <w2p-developers@web2project.net>
    Copyright (c) 2003-2007 The dotProject Development Team <core-developers@dotproject.net>

	* *	 * INSTALLATION INSTRUCTIONS * * *
	Point your browser to install/index.php and follow the prompts. It is no 
	longer necessary to manually create config.php unless the web server cannot 
	write to the includes directory.
		
	Regardless, this file is used as a template for the autogeneration of the 
	config.php during the installation process and should not by modified by hand.
*/

$w2Pconfig['dbtype'] = '[DBTYPE]'; // ONLY MySQL is supported at present
$w2Pconfig['dbhost'] = '[DBHOST]';
$w2Pconfig['dbname'] = '[DBNAME]'; // Your web2Project Database Name
$w2Pconfig['dbuser'] = '[DBUSER]'; // Your MySQL Username
$w2Pconfig['dbpass'] = '[DBPASS]'; // Your MySQL Password
$w2Pconfig['dbprefix'] = '[DBPREFIX]'; // The prefix used for db table names

// set this value to true to use persistent database connections
$w2Pconfig['dbpersist'] = false;

/*
 * Set this value to true to use UTF8 for database connection, 
 * keep it false to use system default.
 * 
 * 1) KEEP THIS OPTION FALSE IF YOU DO NOT USE MYSQL 
 *    (you have not set $w2Pconfig['dbtype'] = 'mysql') 
 *    OR YOUR MYSQL SERVER VERSION IS < 5.0.7 !
 * 2) IF YOU SET THIS TO TRUE AND HAVEN'T CONVERTED THE DATA ON THE DATABASE TO UTF8, 
 *    YOUR DATA CHARACTERS WILL MOST PROBABLY END UP SCRAMBLED.
 * YOU HAVE BEEN WARNED!!!
 */
$w2Pconfig['dbconn_charset'] = [DBCONN_CHARSET];

/***************** Configuration for DEVELOPERS use only! ******/
// Root directory and base_url are automatically set to avoid
// getting them wrong.  They are not the same.
$w2Pconfig['root_dir'] = W2P_BASE_DIR;
$w2Pconfig['base_url'] = W2P_BASE_URL;
