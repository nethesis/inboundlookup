#!/usr/bin/env php
<?php

#
# Copyright (C) 2022 Nethesis S.r.l.
# SPDX-License-Identifier: GPL-3.0-or-later
#

set_time_limit(10);
include_once ("/etc/freepbx_db.conf");
define("AGIBIN_DIR", "/var/lib/asterisk/agi-bin");
include(AGIBIN_DIR."/phpagi.php");

global $db;

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
                        @$agi->verbose("Name = $name, Company = $company, Number = $number, source = API");
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
        $sql = "SELECT * FROM inboundlookup";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($stmt->errorCode() != 0) {
            @$agi->verbose("Error: ".$stmt->errorInfo()[2]);
            exit(1);
        }

        //Setup database connection:
        $db_user = $results[0]['mysql_username'];
        $db_pass = $results[0]['mysql_password'];
        $db_host = $results[0]['mysql_host'];
        $db_name = $results[0]['mysql_dbname'];
        $db_engine = 'mysql';

        $lookupdb = new PDO(
            $db_engine.':host='.$db_host.';dbname='.$db_name,
            $db_user,
            $db_pass,
        );
        // check for errors
        if($lookupdb instanceof PDOException) {
            @$agi->verbose("Error conecting to asterisk database, skipped");
            exit(1);
        }

        $sql=preg_replace('/\[NUMBER\]/',$number,$results[0]['mysql_query']);
        @$agi->verbose($sql);

        $stmt = $lookupdb->prepare($sql);
        $stmt->execute();
        $res = $stmt->fetchAll();

        if (empty($res)) {
            //remove international prefix from number
            if (substr($number,0,1) === '+' ) {
                $mod_number = substr($number,3);
                $sql=preg_replace('/\[NUMBER\]/',$mod_number,$results[0]['mysql_query']);
                @$agi->verbose($sql);
                $stmt = $lookupdb->prepare($sql);
                $stmt->execute();
                $res = $stmt->fetchAll();
            } elseif ( substr($number,0,2) === '00') {
                $mod_number = substr($number,4);
                $sql=preg_replace('/\[NUMBER\]/',$mod_number,$results[0]['mysql_query']);
                @$agi->verbose($sql);
                $stmt = $lookupdb->prepare($sql);
                $stmt->execute();
                $res = $stmt->fetchAll();
            }
        }

        if ($stmt->errorCode() != 0) {
            @$agi->verbose("Error: ".$stmt->errorInfo()[2]);
            exit(1);
        }

        $namecount = 0;

        if (!empty($res)) {
            //get company
            foreach ($res as $row) {
                if (!empty($row[1])) {
                    $company = $row[1];
                    @$agi->verbose("Company = $company");
                    break; //company setted, no need to continue
                }
            }
            //get name
            foreach ($res as $row) {
                if (!empty($row[0])) {
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
        @$agi->verbose("Name = $name, Company = $company, Number = $number, source = Mysql");
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
@$agi->verbose("Name = \"$name\", Company = \"$company\", Number = \"$number\"");
