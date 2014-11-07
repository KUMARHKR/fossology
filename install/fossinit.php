#!/usr/bin/php
<?php
/***********************************************************
 Copyright (C) 2008-2014 Hewlett-Packard Development Company, L.P.
 Copyright (C) 2014 Siemens AG

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 version 2 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 ***********************************************************/

/** \brief Print Usage statement.
 *  \return No return, this calls exit.
 **/
function explainUsage()
{
  global $argv;

  $usage = "Usage: " . basename($argv[0]) . " [options]
  Update FOSSology database. This should be used immediately after an install or update. Options are:
  -c  path to fossology configuration files
  -d  {database name} default is 'fossology'
  -f  {file} update the schema with file generated by schema-export.php
  -l  update the license_ref table with fossology supplied licenses
  -r  {prefix} drop database with name starts with prefix
  -v  enable verbose preview (prints sql that would happen, but does not execute it, DB is not updated)
  -h  this help usage";
  print "$usage\n";
  exit(0);
}


/**
 * @file fossinit.php
 * @brief This program applies core-schema.dat to the database (which
 *        must exist) and updates the license_ref table.
 * @return 0 for success, 1 for failure.
 **/

use Fossology\Lib\Db\DbManager;

$groupInfo = posix_getgrnam("fossy");
posix_setgid($groupInfo['gid']);
$group = `groups`;
if (!preg_match("/\sfossy\s/",$group) && (posix_getgid()!=$groupInfo['gid']))
{
  print "FATAL: You must be in group 'fossy' to update the FOSSology database.\n";
  exit(1);
}

/* Note: php 5 getopt() ignores options not specified in the function call, so add
 * dummy options in order to catch invalid options.
 */
$AllPossibleOpts = "abc:d:ef:ghijklmnopqr:stuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";

/* defaults */
$Verbose = false;
$DatabaseName = "fossology";
$UpdateLiceneseRef = false;
$sysconfdir = '';
$delDbPattern = 'the option -rfosstest will drop data bases with datname like "fosstest%"';

/* command-line options */
$Options = getopt($AllPossibleOpts);
foreach($Options as $optKey => $optVal)
{
  switch($optKey)
  {
    case 'c': /* set SYSCONFIDR */
      $sysconfdir = $optVal;
      break;
    case 'd': /* optional database name */
      $DatabaseName = $optVal;
      break;
    case 'f': /* schema file */
      $SchemaFilePath = $optVal;
      break;
    case 'h': /* help */
      explainUsage();
    case 'l': /* update the license_ref table */
      $UpdateLiceneseRef = true;
      break;
    case 'v': /* verbose */
      $Verbose = true;
      break;
    case 'r':
      $delDbPattern = $optVal ? "$optVal%" : "fosstest%";
      break;
    default:
      echo "Invalid Option \"$optKey\".\n";
      explainUsage();
  }
}

/* Set SYSCONFDIR and set global (for backward compatibility) */
$SysConf = bootstrap($sysconfdir);
require_once("$MODDIR/vendor/autoload.php");
require_once("$MODDIR/lib/php/common-db.php");
require_once("$MODDIR/lib/php/common-container.php");
require_once("$MODDIR/lib/php/common-cache.php");
require_once("$MODDIR/lib/php/common-sysconfig.php");

/* Initialize global system configuration variables $SysConfig[] */
ConfigInit($SYSCONFDIR, $SysConf);

/** delete from copyright where pfile_fk not in (select pfile_pk from pfile) */
/** add foreign constraint on copyright pfile_fk if not exist */
/** comment out for 2.5.0
require_once("$LIBEXECDIR/dbmigrate_2.0-2.5-pre.php");
Migrate_20_25($Verbose);
*/

if (empty($SchemaFilePath)) $SchemaFilePath = "$MODDIR/www/ui/core-schema.dat";

if (!file_exists($SchemaFilePath))
{
  print "FAILED: Schema data file ($SchemaFilePath) not found.\n";
  exit(1);
}

require_once("$MODDIR/lib/php/libschema.php");
$FailMsg = $libschema->applySchema($SchemaFilePath, $Verbose, $DatabaseName);
if ($FailMsg)
{
  print "ApplySchema failed: $FailMsg\n";
  exit(1);
}
// writeDataProperties($dbManager);
$Filename = "$MODDIR/www/ui/init.ui";
$flagRemoved = !file_exists($Filename);
if (!$flagRemoved)
{
  if ($Verbose)
  {
    print "Removing flag '$Filename'\n";
  }
  if (is_writable("$MODDIR/www/ui/"))
  {
    $flagRemoved = unlink($Filename);
  }
}
if (!$flagRemoved)
{
  print "Failed to remove $Filename\n";
  print "Remove this file to complete the initialization.\n";
}
else
{
  print "Database schema update completed successfully.\n";
}

/* initialize the license_ref table */
if ($UpdateLiceneseRef) 
{
  $row = $dbManager->getSingleRow("SELECT count(*) FROM license_ref",array(),'license_ref.count');
  if ($row['count'] >  0) {
    print "Update reference licenses\n";
    initLicenseRefTable(false);
  }
  else if ($row['count'] ==  0) {
    /** import licenseref.sql */
    $sqlstmts = file_get_contents("$LIBEXECDIR/licenseref.sql");
    $dbManager->queryOnce($sqlstmts,$stmt=__METHOD__."$LIBEXECDIR/licenseref.sql");

    $row_max = $dbManager->getSingleRow("SELECT max(rf_pk) from license_ref",array(),'license_ref.max.rf_pk');
    $current_license_ref_rf_pk_seq = $row_max['max'];
    $dbManager->getSingleRow("SELECT setval('license_ref_rf_pk_seq', $current_license_ref_rf_pk_seq)",array(),
            'set next license_ref_rf_pk_seq value');

    print "fresh install, import licenseref.sql \n";
  }
}

if (array_key_exists('r', $Options))
{
  $dbManager->prepare(__METHOD__.".getDelDbNames",'SELECT datname FROM pg_database WHERE datistemplate = false and datname like $1');
  $resDelDbNames = $dbManager->execute(__METHOD__.".getDelDbNames",array($delDbPattern));
  $delDbNames=pg_fetch_all($resDelDbNames);
  pg_free_result($resDelDbNames);
  foreach ($delDbNames as $deleteDatabaseName)
  {
    $dbManager->queryOnce("DROP DATABASE $deleteDatabaseName[datname]");
  }
  if ($Verbose)
  {
    echo "dropped " . count($delDbNames) . " databases ";
  }
}

/* migration */
$sysconfig = $dbManager->createMap('sysconfig','variablename','conf_value');
global $LIBEXECDIR;
if(!array_key_exists('Release', $sysconfig)){
  require_once("$LIBEXECDIR/dbmigrate_2.0-2.1.php");  // this is needed for all new installs from 2.0 on
  Migrate_20_21($Verbose);
  require_once("$LIBEXECDIR/dbmigrate_2.1-2.2.php");
  print "Migrate data from 2.1 to 2.2 in $LIBEXECDIR\n";
  Migrate_21_22($Verbose);
  require_once("$LIBEXECDIR/dbmigrate_2.5-2.6.php");
  migrate_25_26($Verbose);
  $dbManager->insertTableRow('sysconfig',
          array('variablename'=>'Release','conf_value'=>'2.6','ui_label'=>'Release','vartype'=>2,'group_name'=>'Release','description'=>''));
  $sysconfig['Release'] = '2.6';
}
if($sysconfig['Release'] == '2.6')
{
  $writer = new dataPropertiesWriter($dbManager);
  $writer->writeDataProperties();
  $dbManager->getSingleRow("UPDATE sysconfig SET conf_value=$2 WHERE variablename=$1",array('Release','2.6.3'),$sqlLog='update.sysconfig.release');
}

/* sanity check */
require_once ("$LIBEXECDIR/sanity_check.php");
$checker = new SanityChecker($dbManager,$Verbose);
$errors = $checker->check();

if($errors>0)
{
  echo "ERROR: $errors sanity check".($errors>1?'s':'')." failed\n";
}
exit($errors);








class dataPropertiesWriter{
  var $dbManager;
  var $dataProperties;

  function __construct(DbManager &$dbManager)
  {
    $this->dbManager = &$dbManager;
    global $LIBEXECDIR;
    $this->dataProperties = $this->readDataPropertiesFromFile("$LIBEXECDIR/dataProperties.dat");
  }
  
  private function readDataPropertiesFromFile($filename){
    if (!file_exists($filename))
    {
      throw new \Exception("FAILED: Schema data file ($filename) not found.");
    }
    $dataPropertiesStorage = array(); // filled in next line
    eval( file_get_contents($filename) );
    return $dataPropertiesStorage;
  }
  
  function writeDataProperties(){
    foreach($this->dataProperties as $tableColumn=>$columnContent)
    {
      $tableColumnList =  explode('.', $tableColumn);
      if(count($tableColumnList)==2)
      {
        list($table,$column) = $tableColumnList;
        $this->writeListedDataProperties($table,$column,$columnContent);
      }
      else if (count($tableColumnList) == 3)
      {
        list($table, $keyColumn, $valColumn) = $tableColumnList;
        $this->writeMappedDataProperties($table, $keyColumn, $valColumn, $columnContent);
      }
    }
  }

  function writeMappedDataProperties($table, $keyColumn, $valColumn, $columnContent){
    $this->dbManager->begin();
    $this->dbManager->queryOnce($sqlStatement="TRUNCATE $table CASCADE");
    foreach($columnContent as $key=>$val)
    {
      $this->dbManager->insertInto($table,"$keyColumn,$valColumn",array($key,$val));
    }
    $this->dbManager->commit();
  }

  function writeListedDataProperties($table,$column,$columnContent){
    $stmt=__METHOD__.".$table.$column";
    $this->dbManager->prepare($stmt,"SELECT $column FROM $table");
    $result = $this->dbManager->execute($stmt);
    $oldColumnContent = array();
    while ($row = $this->dbManager->fetchArray($result))
    {
      $oldColumnContent[] = $row[$column];
    }
    $this->dbManager->freeResult($result);
    $diffColumnContent = array_diff($columnContent,$oldColumnContent);
    foreach($diffColumnContent as $val)
    {
      $this->dbManager->insertInto($table,$column,array($val));
    }
  }
}

/**
 * \brief Load the license_ref table with licenses.
 *
 * \param $Verbose display database load progress information.  If $Verbose is false,
 * this function only prints errors.
 *
 * \return 0 on success, 1 on failure
 **/
function initLicenseRefTable($Verbose)
{
  global $LIBEXECDIR;
  global $dbManager;

  if (!is_dir($LIBEXECDIR)) {
    print "FATAL: Directory '$LIBEXECDIR' does not exist.\n";
    return (1);
  }
  $dir = opendir($LIBEXECDIR);
  if (!$dir) {
    print "FATAL: Unable to access '$LIBEXECDIR'.\n";
    return (1);
  }
  
  $dbManager->queryOnce("BEGIN");
  $dbManager->queryOnce("DROP TABLE IF EXISTS license_ref_2",$stmt=__METHOD__.'.dropAncientBackUp');
  /* create a new temp table structure only - license_ref_2 */
  $dbManager->queryOnce("CREATE TABLE license_ref_2 as select * from license_ref WHERE 1=2",$stmt=__METHOD__.'.backUpData');

  /** import licenseref.sql */  
  $sqlstmts = file_get_contents("$LIBEXECDIR/licenseref.sql");
  $sqlstmts = str_replace("license_ref","license_ref_2", $sqlstmts);
  $dbManager->queryOnce($sqlstmts);
  
  $dbManager->prepare(__METHOD__.".newLic", "select * from license_ref_2");
  $result_new = $dbManager->execute(__METHOD__.".newLic");
  
  $dbManager->prepare(__METHOD__.'.licenseRefByShortname','SELECT * from license_ref where rf_shortname=$1');
  /** traverse all records in user's license_ref table, update or insert */
  while ($row = pg_fetch_assoc($result_new))
  {
    $rf_shortname = $row['rf_shortname'];
    $escaped_name = pg_escape_string($rf_shortname);
    $result_check = $dbManager->execute(__METHOD__.'.licenseRefByShortname',array($rf_shortname));
    $count = pg_num_rows($result_check);

    $rf_text = pg_escape_string($row['rf_text']);
    $rf_url = pg_escape_string($row['rf_url']);
    $rf_fullname = pg_escape_string($row['rf_fullname']);
    $rf_notes = pg_escape_string($row['rf_notes']);
    $rf_active = $row['rf_active'];
    $marydone = $row['marydone'];
    $rf_text_updatable = $row['rf_text_updatable'];
    $rf_detector_type = $row['rf_detector_type'];

    if ($count) // update when it is existing
    {
      $row_check = pg_fetch_assoc($result_check);
      pg_free_result($result_check);
      $rf_text_check = pg_escape_string($row_check['rf_text']);
      $rf_url_check = pg_escape_string($row_check['rf_url']);
      $rf_fullname_check = pg_escape_string($row_check['rf_fullname']);
      $rf_notes_check = pg_escape_string($row_check['rf_notes']);
      $rf_active_check = $row_check['rf_active'];
      $marydone_check = $row_check['marydone'];
      $rf_text_updatable_check = $row_check['rf_text_updatable'];
      $rf_detector_type_check = $row_check['rf_detector_type'];

      $sql = "UPDATE license_ref set ";
      if ($rf_text_check != $rf_text && !empty($rf_text) && !(stristr($rf_text, 'License by Nomos')))  $sql .= " rf_text='$rf_text',";
      if ($rf_url_check != $rf_url && !empty($rf_url))  $sql .= " rf_url='$rf_url',";
      if ($rf_fullname_check != $rf_fullname && !empty($rf_fullname))  $sql .= " rf_fullname ='$rf_fullname',";
      if ($rf_notes_check != $rf_notes && !empty($rf_notes))  $sql .= " rf_notes ='$rf_notes',";
      if ($rf_active_check != $rf_active && !empty($rf_active))  $sql .= " rf_active ='$rf_active',";
      if ($marydone_check != $marydone && !empty($marydone))  $sql .= " marydone ='$marydone',";
      if ($rf_text_updatable_check != $rf_text_updatable && !empty($rf_text_updatable))  $sql .= " rf_text_updatable ='$rf_text_updatable',";
      if ($rf_detector_type_check != $rf_detector_type && !empty($rf_detector_type))  $sql .= " rf_detector_type = '$rf_detector_type',";
      $sql = substr_replace($sql ,"",-1);

      if ($sql != "UPDATE license_ref set") // check if have something to update
      {
        $sql .= " where rf_shortname = '$escaped_name'";
        $dbManager->queryOnce($sql);
      }
    }
    else  // insert when it is new
    {
      pg_free_result($result_check);
      $sql = "INSERT INTO license_ref (rf_shortname, rf_text, rf_url, rf_fullname, rf_notes, rf_active, rf_text_updatable, rf_detector_type, marydone)"
              . "VALUES ('$escaped_name', '$rf_text', '$rf_url', '$rf_fullname', '$rf_notes', '$rf_active', '$rf_text_updatable', '$rf_detector_type', '$marydone');";
      $dbManager->queryOnce($sql);
    }
  }
  pg_free_result($result_new);

  $dbManager->queryOnce("DROP TABLE license_ref_2");
  $dbManager->queryOnce("COMMIT");

  return (0);
} // initLicenseRefTable()

/**
 * \brief Determine SYSCONFDIR, parse fossology.conf
 *
 * \param $sysconfdir Typically from the caller's -c command line parameter
 *
 * \return the $SysConf array of values.  The first array dimension
 * is the group, the second is the variable name.
 * For example:
 *  -  $SysConf[DIRECTORIES][MODDIR] => "/mymoduledir/
 *
 * The global $SYSCONFDIR is also set for backward compatibility.
 *
 * \Note Since so many files expect directory paths that used to be in pathinclude.php
 * to be global, this function will define the same globals (everything in the
 * DIRECTORIES section of fossology.conf).
 */
function bootstrap($sysconfdir="")
{
  $rcfile = "fossology.rc";

  if (empty($sysconfdir))
  {
    $sysconfdir = getenv('SYSCONFDIR');
    if ((false===$sysconfdir) && file_exists($rcfile))
    {
      $sysconfdir = file_get_contents($rcfile);
    }
    if ($sysconfdir === false)
    {
      $text = _("FATAL! System Configuration Error, no SYSCONFDIR.");
      echo "$text\n";
      exit(1);
    }
  }

  $sysconfdir = trim($sysconfdir);
  $GLOBALS['SYSCONFDIR'] = $sysconfdir;

  /*************  Parse fossology.conf *******************/
  $ConfFile = "{$sysconfdir}/fossology.conf";
  if (!file_exists($ConfFile))
  {
    $text = _("FATAL! Missing configuration file: $ConfFile");
    echo "$text\n";
    exit(1);
  }
  $SysConf = parse_ini_file($ConfFile, true);
  if ($SysConf === false)
  {
    $text = _("FATAL! Invalid configuration file: $ConfFile");
    echo "$text\n";
    exit(1);
  }

  /* evaluate all the DIRECTORIES group for variable substitutions.
   * For example, if PREFIX=/usr/local and BINDIR=$PREFIX/bin, we
   * want BINDIR=/usr/local/bin
   */
  foreach($SysConf['DIRECTORIES'] as $var=>$assign)
  {
    $toeval = "\$$var = \"$assign\";";
    eval($toeval);

    /* now reassign the array value with the evaluated result */
    $SysConf['DIRECTORIES'][$var] = ${$var};
    $GLOBALS[$var] = ${$var};
  }

  if (empty($MODDIR))
  {
    $text = _("FATAL! System initialization failure: MODDIR not defined in $SysConf");
    echo "$text\n";
    exit(1);
  }

  //require("i18n.php"); DISABLED until i18n infrastructure is set-up.
  require_once("$MODDIR/lib/php/Plugin/FO_Plugin.php");
  require_once("$MODDIR/lib/php/common.php");
  return $SysConf;
}
