#!/usr/bin/env php
<?php

#
# Copyright (C) 2022 Nethesis S.r.l.
# SPDX-License-Identifier: GPL-3.0-or-later
#

set_time_limit(10);
include_once ("/etc/freepbx.conf");
define("AGIBIN_DIR", "/var/lib/asterisk/agi-bin");
include(AGIBIN_DIR."/phpagi.php");
$DEBUG = false;
error_reporting(0);


global $db;
/**********************************************************************************************************************/
function inboundlookup_debug($text) {
    global $DEBUG;
    if ($DEBUG) {
        return inboundlookup_error($text,'DEBUG');
    }
}
function inboundlookup_error($text,$tag='ERROR') {
    global $agi;
    if (is_array($text))
    {
        @$agi->verbose("inboundlookup [".$tag."] ".print_r($text,true));
    } else {
        @$agi->verbose("inboundlookup [".$tag."] ".$text);
    }
}
/******************************************************/

$agi = new AGI();
$number =  $argv[1];
$name = '';
$company = '';
$apiFlag = false;
$apiDir = '/usr/src/nethvoice/lookup.d';
$apiFile = '';

if (strlen($number)> 4) {
    //search into dir custom API script(s)
    if (is_dir($apiDir)) {
        foreach (scandir($apiDir) as $file) {
            //check if it's a file also it's executable
            $apiFile = $apiDir . '/' . $file;
            if (is_file($apiFile)) {
                if (is_executable($apiFile)) {
                    //call the exe file and check if it returns any valid json
                    $shellCmd = escapeshellcmd($apiFile . ' ' . $number);
                    $apiResult = shell_exec($shellCmd);
                    //expects a json obj
                    if ($apiResult != '') {
                        $jsonData = json_decode($apiResult);
                        $name = $jsonData->name;
                        $company = $jsonData->company;
                        if ($name == '' && $company == '') {
                            //continue with the next exe file
                            continue;
                        }
                        //almost one exe file returns valid info
                        inboundlookup_debug("Name = $name, Company = $company, Number = $number, source = API");
                        $apiFlag = true;
                        break;
                    }
                }
            }
        }
    }
    //look up $name and $company via Mysql query
    if (!$apiFlag) {
        //get database data
        $results = $db->getAll("SELECT * FROM inboundlookup","getRow",DB_FETCHMODE_ASSOC);
        if (DB::isError($results) || empty($results)) {
            inboundlookup_error ('Error getting inboundlookup data');
            exit(1);
        }

        //Setup database connection:
        $db_user = $results[0]['mysql_username'];
        $db_pass = $results[0]['mysql_password'];
        $db_host = $results[0]['mysql_host'];
        $db_name = $results[0]['mysql_dbname'];
        $db_engine = 'mysql';
        $name = '';
        $userfield = '';
        $datasource = $db_engine.'://'.$db_user.':'.$db_pass.'@'.$db_host.'/'.$db_name;
        $lookupdb = @DB::connect($datasource); // attempt connection
        if($lookupdb instanceof DB_Error) {
            inboundlookup_error("Error conecting to asterisk database, skipped");
            exit(1);
        }


        $sql=preg_replace('/\[NUMBER\]/',$number,$results[0]['mysql_query']);
        inboundlookup_debug ($sql);

        $res = @$lookupdb->getAll($sql,DB_FETCHMODE_ORDERED);
        inboundlookup_debug ($res);

        if (empty($res)) {
            //remove international prefix from number
            if (substr($number,0,1) === '+' ) {
                $mod_number = substr($number,3);
                $sql=preg_replace('/\[NUMBER\]/',$mod_number,$results[0]['mysql_query']);
                inboundlookup_debug ($sql);
                $res = @$lookupdb->getAll($sql,DB_FETCHMODE_ORDERED);
            } elseif ( substr($number,0,2) === '00') {
                $mod_number = substr($number,4);
                $sql=preg_replace('/\[NUMBER\]/',$mod_number,$results[0]['mysql_query']);
                inboundlookup_debug ($sql);
                $res = @$lookupdb->getAll($sql,DB_FETCHMODE_ORDERED);
            }
            inboundlookup_debug ($res);
        }

        if ($lookupdb->isError($res)){
            inboundlookup_debug("$sql\nERROR: ".$res->getMessage());
            exit(1);
        }

        $namecount = 0;

        if (!empty($res)) {
            //get company
            foreach ($res as $row) {
                if (isset($row[1]) && !is_null($row[1]) && !empty($row[1])) {
                    $company = $row[1];
                    inboundlookup_debug("Company = $company");
                    break; //company setted, no need to continue
                }
            }
            //get name
            foreach ($res as $row) {
                if (isset($row[0]) && !is_null($row[0]) && !empty($row[0])) {
                    $name = $row[0];
                    //if company is setted, make sure that there is only one name that correspond to this number, clear name if there are more than one
                    if ($company != '') {
                        $namecount++;
                        if ( $namecount > 1) {
                            $name = '';
                            break;
                        }
                    } else {
                        break;
                    }
                }
            }
        }
        inboundlookup_debug("Name = $name, Company = $company, Number = $number, source = Mysql");
    }
}

if ($name === '' && $company !== '') $displayname = $company;
elseif ($name !== '' && $company !== '') $displayname = "$name ($company)";
elseif ($name !== '' && $company === '') $displayname = $name;
else $displayname = $number;

@$agi->set_variable("CONNECTEDLINE(name,i)","$displayname");
@$agi->set_variable("CALLERID(name)","$displayname");
if ($name !== '' ) @$agi->set_variable("CDR(cnam)","$name");
if ($company !== '' ) @$agi->set_variable("CDR(ccompany)","$company");
@$agi->verbose("Name = \"$name\", Company = \"$company\" Number = \"$number\"");

exit(0);
