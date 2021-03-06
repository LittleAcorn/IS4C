<?php
/*******************************************************************************

    Copyright 2009 Whole Foods Co-op

    This file is part of CORE-POS.

    CORE-POS is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    CORE-POS is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

include(dirname(__FILE__) . '/../../config.php');
if (!class_exists('FannieAPI')) {
    include_once($FANNIE_ROOT.'classlib2.0/FannieAPI.php');
}
if (basename($_SERVER['PHP_SELF']) != basename(__FILE__)) {
    return;
}
$dbc = FannieDB::get($FANNIE_OP_DB);

switch(FormLib::get_form_value('action')){
case 'fetch':
    $prep = $dbc->prepare_statement("SELECT u.upc,p.description FROM
            upcLike AS u 
                " . DTrans::joinProducts('u', 'p', 'INNER') . "
            WHERE u.likeCode=?
            ORDER BY p.description");
    $res = $dbc->exec_statement($prep,array(FormLib::get_form_value('lc',0)));
    $ret = "";
    while($row = $dbc->fetch_row($res)){
        $ret .= "<a style=\"font-size:90%;\" href={$FANNIE_URL}item/itemMaint.php?upc=$row[0]>";
        $ret .= $row[0]."</a> ".substr($row[1],0,25)."<br />";
    }
    echo $ret;
    break;
}

