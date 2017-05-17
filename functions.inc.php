<?php
//Copyright (C) nethesis srl. (info@nethesis.it)
//
//This program is free software; you can redistribute it and/or
//modify it under the terms of the GNU General Public License
//as published by the Free Software Foundation; either version 2
//of the License, or (at your option) any later version.
//
//This program is distributed in the hope that it will be useful,
//but WITHOUT ANY WARRANTY; without even the implied warranty of
//MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//GNU General Public License for more details.

if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

function inboundlookup_hookGet_config($engine) {
    global $ext;
    switch($engine) {
        case "asterisk":
            $routes = core_routing_list();
            if (!empty($routes) && !is_null(inboundlookup_get())) {
                $ext->splice('macro-user-callerid', 's','cnum', new ext_agi('/var/lib/asterisk/agi-bin/inboundlookup.php,${DIAL_NUMBER},${DB(AMPUSER/${AMPUSER}/cidname)}'),"",-4);
#                $ext->splice('macro-dialout-trunk', 's','customtrunk', new ext_agi('/var/lib/asterisk/agi-bin/inboundlookup.php,${DIAL_NUMBER},${DB(AMPUSER/${AMPUSER}/cidname)}'),"",-4);
            }
        break;
    }
}

function inboundlookup_get(){
        $results = sql("SELECT * FROM inboundlookup ","getRow",DB_FETCHMODE_ASSOC);
        return isset($results)?$results:null;
}

function inboundlookup_del(){
    global $db;
    sql('TRUNCATE inboundlookup');
}

function inboundlookup_add($post){
    global $db;
    $mysql_host = $db->escapeSimple($post['mysql_host']);
    $mysql_dbname = $db->escapeSimple($post['mysql_dbname']);
    $mysql_query = $db->escapeSimple($post['mysql_query']);
    $mysql_username = $db->escapeSimple($post['mysql_username']);
    $mysql_password = $db->escapeSimple($post['mysql_password']);
    $mysql_charset = $db->escapeSimple($post['mysql_charset']);
    sql('TRUNCATE inboundlookup');
    $sql = "INSERT INTO inboundlookup
        (mysql_host, mysql_dbname, mysql_query, mysql_username, mysql_password, mysql_charset)
        VALUES
        ('$mysql_host', '$mysql_dbname', '$mysql_query', '$mysql_username', '$mysql_password', '$mysql_charset')";
    error_log($sql);
    $results = sql($sql);
}

